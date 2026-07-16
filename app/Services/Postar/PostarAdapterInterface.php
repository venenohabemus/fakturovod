<?php

namespace App\Services\Postar;

/**
 * Abstraction over a certified digital postman (poštár). ePošťák is the first
 * implementation; the interface is our permanent insurance against lock-in.
 *
 * MVP covers the outbound path (send + status). Inbound methods
 * (fetchIncoming, confirmReceipt) arrive in phase 2.
 */
interface PostarAdapterInterface
{
    /**
     * Hands a UBL 2.1 document to the poštár for Peppol delivery.
     *
     * @param string $ublXml          the UBL 2.1 Invoice/CreditNote XML
     * @param string $receiverPeppolId Peppol participant id, e.g. "0245:0000000001"
     * @param string $idempotencyKey   stable key so retries don't duplicate delivery
     *
     * @throws PostarException on validation, auth, or transport failure
     */
    public function sendInvoice(string $ublXml, string $receiverPeppolId, string $idempotencyKey): SendResult;

    /**
     * Fetches the current delivery status of a previously sent document.
     *
     * @throws PostarException
     */
    public function getStatus(string $documentId): DeliveryStatus;
}
