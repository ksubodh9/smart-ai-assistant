<?php

/*
 * MaddoxPay's vocabulary, as it must appear in the host's
 * config/smart-ai-assistant.php. The feature tests run with it so the
 * characterization tests keep pinning MaddoxPay's behaviour, while the
 * package defaults stay generic (see GenericDefaultsTest).
 */
return [
    'default_service' => 'AEPS',

    'understanding' => [
        'patterns' => [
            'greeting' => [
                '/^(hi|hello|hey|hii+|helo|hlo|namaste|namaskar)[\s\!\.\?]*$/iu',
                '/^(good\s*(morning|afternoon|evening|night|day))[\s\!\.\?]*$/iu',
                '/^(howdy|sup|yo|hiya)[\s\!\.\?]*$/iu',
            ],
            'vague' => [
                '/^(help|help me|need help|i need help)[\s\!\.\?]*$/iu',
                '/^(issue|problem|error|not working)[\s\!\.\?]*$/iu',
                '/^(something (is )?(wrong|broken|not working))[\s\!\.\?]*$/iu',
                '/^(it\'?s? not working)[\s\!\.\?]*$/iu',
                '/^(please help)[\s\!\.\?]*$/iu',
                '/^(kuch gadbad hai|kaam nahi kar raha)[\s\!\.\?]*$/iu',
            ],
            'abuse_mild' => [
                '/\b(damn|crap|sucks|stupid|useless|rubbish|pathetic|worst)\b/iu',
                '/\b(bakwas|bekaar|wahiyat|ghatiya)\b/iu',
            ],
            'abuse_severe' => [
                '/\b(f+u+c+k+|shit|bastard|bitch|ass+hole)\b/iu',
                '/\b(kill|murder|die|threat)\b/iu',
                '/\b(madarch[o0]d|bhench[o0]d|chutiya|gandu|harami|saala|kutta|kamina)\b/iu',
                '/\b(randi|hijra|chakka)\b/iu',
            ],
        ],
        'categories' => [
            'PAN'      => ['pan', 'nsdl', 'uti', 'correction', 'pan card'],
            'RECHARGE' => ['recharge', 'topup', 'jio', 'airtel', 'vi', 'vodafone', 'dth', 'mobile'],
            'AEPS'     => ['aeps', 'withdrawal', 'balance enquiry', 'mini statement', 'fingerprint', 'biometric', 'aadhaar pay'],
            'PAYOUT'   => ['payout', 'transfer', 'imps', 'neft', 'bank', 'account', 'beneficiary'],
            'KYC'      => ['kyc', 'document', 'aadhaar', 'verification', 'upload', 'ekyc'],
            'IRCTC'    => ['irctc', 'train', 'booking', 'cancellation', 'ticket', 'railway'],
        ],
    ],

    'responses' => [
        'vague' => [
            'en' => 'Please specify the error message or the service (e.g., AEPS, PAN) you are having trouble with.',
        ],
        'escalation' => [
            'en' => "Your request has been noted. Please use the 'Raise Ticket' option to connect with our support team, or call our helpline for immediate assistance.",
            'hi' => "आपका अनुरोध दर्ज किया गया है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें या तुरंत सहायता के लिए हमारी हेल्पलाइन पर कॉल करें।",
        ],
        'unknown' => [
            'en' => "this specific error is not yet documented.\n\nIf this issue is urgent, please use the 'Raise Ticket' option to contact support.",
            'hi' => "यह त्रुटि अभी दस्तावेज़ में नहीं है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें।",
        ],
    ],
];
