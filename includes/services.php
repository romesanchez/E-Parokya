<?php
// Service catalogue. To add a service: add it here, add a matching entry in
// guides.php, and add it to the CHECK constraint in database/schema.sql.

const SERVICE_LABELS = [
    'baptism'         => 'Baptism',
    'wedding'         => 'Wedding',
    'funeral'         => 'Funeral / Requiem',
    'blessing'        => 'House Blessing',
    'first_communion' => 'First Communion',
    'confirmation'    => 'Confirmation',
    'mass_intention'  => 'Mass Intention',
];

const STATUS_LABELS = [
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'completed' => 'Completed',
    'declined'  => 'Declined',
];

require_once __DIR__ . '/guides.php';

const FIELD_KEYS = ['serviceType', 'fullName', 'email', 'phone', 'preferredDate', 'notes'];

function service_guide(string $type): array
{
    global $SERVICE_GUIDES;
    return $SERVICE_GUIDES[$type] ?? $SERVICE_GUIDES['baptism'];
}

function field_guide(string $type, string $field): array
{
    global $DEFAULT_FIELD_GUIDES;
    if ($field === 'serviceType') {
        return [
            'title' => 'Service requested',
            'hint' => service_guide($type)['overview'],
            'description' => 'Choose which celebration or request this form is for.',
        ];
    }
    return array_merge($DEFAULT_FIELD_GUIDES[$field] ?? [], service_guide($type)['fields'][$field] ?? []);
}

function field_placeholder(string $type, string $field): ?string
{
    global $DEFAULT_FIELD_GUIDES;
    return field_guide($type, $field)['placeholder'] ?? ($DEFAULT_FIELD_GUIDES[$field]['placeholder'] ?? null);
}

/** Everything the browser-side guide needs, resolved per service. */
function guides_for_js(): array
{
    $out = [];
    foreach (array_keys(SERVICE_LABELS) as $t) {
        $g = service_guide($t);
        $fields = [];
        foreach (FIELD_KEYS as $f) {
            $fields[$f] = field_guide($t, $f);
            $fields[$f]['placeholder'] = $f === 'serviceType' ? null : field_placeholder($t, $f);
        }
        $out[$t] = ['label' => SERVICE_LABELS[$t], 'overview' => $g['overview'], 'tips' => $g['tips'], 'fields' => $fields];
    }
    return $out;
}
