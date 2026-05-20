<?php
/**
 * Server-Side Validation & Sanitization for Sales Quotation
 * Include this in both sales_preview.php and sales_process.php
 */

function validateCSRF($post) {
    if (!isset($post['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $post['csrf_token']);
}

function validateQuotationInput($post) {
    $errors = [];

    // ── Text-only fields (no numbers allowed) ──
    $textOnlyFields = [
        'client_name'      => 'Client Name',
        'attention_to'     => 'Attention To',
        'proposal_purpose' => 'Proposal Purpose'
    ];
    foreach ($textOnlyFields as $field => $label) {
        $value = trim($post[$field] ?? '');
        if (empty($value)) {
            $errors[] = "{$label} is required.";
        } elseif (preg_match('/[0-9]/', $value)) {
            $errors[] = "{$label} must contain letters only (no numbers).";
        }
    }

    // ── Email validation ──
    $email = trim($post['client_email'] ?? '');
    if (empty($email)) {
        $errors[] = "Client Email Address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Client Email Address is not a valid email.";
    }

    // ── Contact number (digits only, 7-15 length) ──
    $contact = preg_replace('/[^0-9]/', '', $post['client_contact'] ?? '');
    if (empty($contact)) {
        $errors[] = "Contact Number is required.";
    } elseif (strlen($contact) < 7 || strlen($contact) > 15) {
        $errors[] = "Contact Number must be between 7 and 15 digits.";
    }

    // ── Required combination fields (text + numbers OK) ──
    $requiredFields = [
        'client_address' => 'Client Address',
        'payment_terms'  => 'Payment Terms',
        'validity_date'  => 'Validity Offer Date',
        'eta'            => 'ETA',
        'quote_date'     => 'Date',
        'quotation_no'   => 'Quotation Number',
        'prepared_by'    => 'Prepared By'
    ];
    foreach ($requiredFields as $field => $label) {
        if (empty(trim($post[$field] ?? ''))) {
            $errors[] = "{$label} is required.";
        }
    }

    // ── Items validation ──
    $items = $post['items'] ?? [];
    if (empty($items) || !is_array($items)) {
        $errors[] = "At least one item must be selected.";
    } else {
        foreach ($items as $i => $item) {
            $qty   = (int)($item['qty'] ?? 0);
            $price = (float)str_replace(',', '', $item['price'] ?? 0);
            if ($qty < 1)    $errors[] = "Item #" . ($i + 1) . ": Quantity must be at least 1.";
            if ($price <= 0) $errors[] = "Item #" . ($i + 1) . ": Price must be greater than 0.";
        }
    }

    return $errors;
}

function sanitizeQuotationInput($post) {
    // Strip numbers from text-only fields
    $post['client_name']      = strtoupper(preg_replace('/[0-9]/', '', trim($post['client_name'] ?? '')));
    $post['attention_to']     = preg_replace('/[0-9]/', '', trim($post['attention_to'] ?? ''));
    $post['proposal_purpose'] = strtoupper(preg_replace('/[0-9]/', '', trim($post['proposal_purpose'] ?? '')));

    // Strip non-digits from contact
    $post['client_contact'] = preg_replace('/[^0-9]/', '', trim($post['client_contact'] ?? ''));

    // Trim all string fields
    $stringFields = ['client_address', 'client_email', 'payment_terms', 'validity_date', 'eta', 'quote_date', 'quotation_no', 'prepared_by', 'inclusions'];
    foreach ($stringFields as $field) {
        $post[$field] = trim($post[$field] ?? '');
    }

    // Sanitize corporate discount
    $post['corporate_discount'] = (float)str_replace(',', '', $post['corporate_discount'] ?? 0);

    return $post;
}