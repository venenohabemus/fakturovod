<?php

namespace App\Http\Controllers;

use App\Models\Mapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MappingController extends Controller
{
    public function index(): View
    {
        return view('mappings.index', [
            'mappings' => Mapping::orderBy('name')->get(),
        ]);
    }

    public function edit(Mapping $mapping): View
    {
        return view('mappings.edit', ['mapping' => $mapping]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['name' => ['required', 'string', 'max:100', 'unique:mappings,name']],
            [
                'name.required' => 'Zadaj názov mapovania.',
                'name.unique' => 'Mapovanie s týmto názvom už existuje.',
            ]
        );

        $mapping = Mapping::create([
            'name' => $request->input('name'),
            'definition' => [
                'version' => 1,
                'source' => ['type' => 'csv', 'delimiter' => ';', 'encoding' => 'UTF-8'],
                'invoice' => new \stdClass(),
            ],
        ]);

        return redirect()
            ->route('mappings.edit', $mapping)
            ->with('status', 'Mapovanie vytvorené — uprav definíciu.');
    }

    public function update(Request $request, Mapping $mapping): RedirectResponse
    {
        $request->validate(
            ['definition' => ['required', 'string']],
            ['definition.required' => 'Definícia nemôže byť prázdna.']
        );

        $decoded = json_decode($request->input('definition'), true);
        if (!is_array($decoded)) {
            return back()
                ->withInput()
                ->withErrors(['definition' => 'Definícia nie je platný JSON: '.json_last_error_msg()]);
        }

        foreach (['source', 'invoice'] as $section) {
            if (!isset($decoded[$section]) || !is_array($decoded[$section])) {
                return back()
                    ->withInput()
                    ->withErrors(['definition' => "V definícii chýba sekcia '{$section}'."]);
            }
        }

        $mapping->update([
            'definition' => $decoded,
            'version' => $mapping->version + 1,
        ]);

        return redirect()
            ->route('mappings.edit', $mapping)
            ->with('status', "Uložené ako verzia {$mapping->version}.");
    }
}
