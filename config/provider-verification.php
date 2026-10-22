<?php

return [
    'documents_by_type' => [
        'foundation' => ['required' => ['organization_proof'], 'instructions' => 'Provide foundation registration or another document establishing legal existence.'],
        'government' => ['required' => ['organization_proof'], 'instructions' => 'Provide an enabling law, official mandate, or certification of the government scholarship program.'],
        'lgu' => ['required' => ['organization_proof'], 'instructions' => 'Provide the local ordinance, program authorization, or official LGU certification.'],
        'school' => ['required' => ['organization_proof'], 'instructions' => 'Provide institutional recognition or an official certification authorizing the scholarship program.'],
        'organization' => ['required' => ['organization_proof'], 'instructions' => 'Provide registration or an official document establishing the organization and scholarship program.'],
        'nonprofit' => ['required' => ['organization_proof'], 'instructions' => 'Provide nonprofit registration or equivalent evidence of legal existence.'],
        'corporate' => ['required' => ['organization_proof'], 'instructions' => 'Provide company registration and evidence of the scholarship program.'],
        'other' => ['required' => ['organization_proof'], 'instructions' => 'Provide appropriate evidence establishing the legitimacy of your scholarship program.'],
        'individual' => ['required' => ['administration_evidence'], 'instructions' => 'Provide a scholarship plan, funding evidence, or appointment showing your independent administration role.'],
    ],
];
