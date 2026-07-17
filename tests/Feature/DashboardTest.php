<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Mapping;
use App\Models\User;
use App\Services\Postar\PostarAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakePostarAdapter;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->app->instance(PostarAdapterInterface::class, new FakePostarAdapter());
    }

    private function sampleMapping(): Mapping
    {
        return Mapping::create([
            'name' => 'legacy-csv',
            'definition' => json_decode(
                file_get_contents(resource_path('samples/mapping-legacy-csv.json')),
                true
            ),
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/faktury')->assertRedirect('/login');
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'tajne-heslo']);

        $this->post('/login', ['email' => $user->email, 'password' => 'tajne-heslo'])
            ->assertRedirect('/faktury');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_bad_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'zle-heslo'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'zle-heslo']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'zle-heslo'])
            ->assertStatus(429);
    }

    public function test_upload_with_failures_redirects_to_error_queue(): void
    {
        $mapping = $this->sampleMapping();

        $file = UploadedFile::fake()->createWithContent(
            'export.csv',
            "cislo;vystavena;splatnost;odberatel;ico_odb;icdph_odb;ulica;mesto;psc;polozka;mj;mnozstvo;cena;dph\n"
            ."FA-B-1;99.07.2026;15.07.2026;Alfa;1;SK1;U;M;811;Tovar;ks;1;10,00;23\n"
        );

        $this->actingAs($this->user)
            ->post('/faktury/import', ['export' => $file, 'mapping_id' => $mapping->id])
            ->assertRedirect('/chyby')
            ->assertSessionHas('error');
    }

    public function test_invoice_list_shows_invoices_with_slovak_statuses(): void
    {
        $this->sampleMapping();
        Invoice::receive(['external_id' => 'FA-1', 'number' => 'FA-1', 'source_payload' => [], 'mapping_definition' => []]);

        $this->actingAs($this->user)
            ->get('/faktury')
            ->assertOk()
            ->assertSee('FA-1')
            ->assertSee('Prijatá');
    }

    public function test_upload_ingests_and_processes_export(): void
    {
        $mapping = $this->sampleMapping();

        $file = UploadedFile::fake()->createWithContent(
            'export.csv',
            file_get_contents(resource_path('samples/legacy-export.csv'))
        );

        $this->actingAs($this->user)
            ->post('/faktury/import', ['export' => $file, 'mapping_id' => $mapping->id])
            ->assertRedirect('/faktury')
            ->assertSessionHas('status');

        $this->assertSame(2, Invoice::count());
        $this->assertSame(
            [InvoiceStatus::Delivered, InvoiceStatus::Delivered],
            Invoice::orderBy('id')->pluck('status')->all()
        );
    }

    public function test_error_queue_lists_all_collected_errors(): void
    {
        $invoice = Invoice::receive(['external_id' => 'FA-ERR', 'source_payload' => [], 'mapping_definition' => []]);
        $invoice->update(['validation_report' => [
            'mapping' => [
                "Hodnota '99.07.2026' poľa 'issue_date' nezodpovedá formátu dátumu 'd.m.Y' (riadok 2).",
                "Chýba povinná hodnota poľa 'quantity' na položke faktúry (riadok 3).",
            ],
            'business' => [
                "IČ DPH odberateľa 'SK2020333333' nie je platné slovenské IČ DPH (SK + 10 číslic deliteľných 11) — skontrolujte preklep.",
            ],
        ]]);
        $invoice->fail('Faktúra obsahuje 3 chyby.');

        $this->actingAs($this->user)
            ->get('/chyby')
            ->assertOk()
            ->assertSee('FA-ERR')
            ->assertSee("nezodpovedá formátu dátumu")
            ->assertSee("Chýba povinná hodnota poľa 'quantity'")
            ->assertSee('nie je platné slovenské IČ DPH');
    }

    public function test_retry_from_dashboard_reprocesses_invoice(): void
    {
        $mapping = $this->sampleMapping();

        $file = UploadedFile::fake()->createWithContent(
            'export.csv',
            "cislo;vystavena;splatnost;odberatel;ico_odb;icdph_odb;ulica;mesto;psc;polozka;mj;mnozstvo;cena;dph\n"
            ."FA-R-1;99.07.2026;15.07.2026;Alfa;11111111;SK2020111115;U;M;811;Tovar;ks;1;10,00;23\n"
        );
        $this->actingAs($this->user)->post('/faktury/import', ['export' => $file, 'mapping_id' => $mapping->id]);

        $invoice = Invoice::first();
        $this->assertSame(InvoiceStatus::Failed, $invoice->status);

        // Fix the stored source data (simulates a corrected export re-ingest scenario:
        // here we edit in place to exercise the retry endpoint).
        $payload = $invoice->source_payload;
        $payload[0]['values']['vystavena'] = '01.07.2026';
        $invoice->update(['source_payload' => $payload]);

        $this->actingAs($this->user)
            ->post("/faktury/{$invoice->id}/znova")
            ->assertRedirect();

        $this->assertSame(InvoiceStatus::Delivered, $invoice->fresh()->status);
    }

    public function test_mapping_editor_validates_json_and_bumps_version(): void
    {
        $mapping = $this->sampleMapping();

        $this->actingAs($this->user)
            ->put("/mapovania/{$mapping->id}", ['definition' => '{nie je json'])
            ->assertSessionHasErrors('definition');
        $this->assertSame(1, $mapping->fresh()->version);

        $valid = json_encode($mapping->definition);
        $this->actingAs($this->user)
            ->put("/mapovania/{$mapping->id}", ['definition' => $valid])
            ->assertRedirect("/mapovania/{$mapping->id}");
        $this->assertSame(2, $mapping->fresh()->version);
    }

    public function test_ubl_download(): void
    {
        $invoice = Invoice::receive(['external_id' => 'FA-U', 'source_payload' => [], 'mapping_definition' => []]);
        $invoice->update(['ubl_xml' => '<Invoice/>', 'number' => 'FA-U']);

        $this->actingAs($this->user)
            ->get("/faktury/{$invoice->id}/ubl")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
