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
                // Polite openers and sign-offs with no question in them
                '/^(sir(\s+ji)?|ji+|dear\s+(sir|madam|team|support(\s+team)?)|ok+|okay|good|thanks?|thank\s*you)[\s\!\.\?]*$/iu',
            ],
            // Package defaults, plus test messages and keyboard mashing
            'noise' => [
                '/^(test|testing|123|abc|xyz|qwerty|asdf)[\s]*$/iu',
                '/^([a-z])\1{2,}$/iu',
                '/^[\W\d\s]+$/iu',
                '/^.{1,2}$/iu',
                '/^(this\s+is\s+)?(a\s+)?test(ing)?\b/iu',
                // One "word" without vowels, except real abbreviations
                '/^(?!(nsdl|dmt|dth|kyc|ckyc|txn|imps|neft|rtgs|upi|bbps|ppf|gst)$)[b-df-hj-np-tv-z]{4,}$/iu',
            ],
            'vague' => [
                '/^(help|help me|need help|i need help)[\s\!\.\?]*$/iu',
                '/^(issue|problem|error|not working)[\s\!\.\?]*$/iu',
                '/^(something (is )?(wrong|broken|not working))[\s\!\.\?]*$/iu',
                '/^(it\'?s? not working)[\s\!\.\?]*$/iu',
                '/^(please help)[\s\!\.\?]*$/iu',
                '/^(kuch gadbad hai|kaam nahi kar (pa )?raha( h(u|oo|ai))?)[\s\!\.\?]*$/iu',
                // Only a service name, optionally with "issue"/"not working" ("payout issue", "pan card")
                '/^(sir\s+|plz\s+|please\s+)?(my\s+)?(pan(\s*card)?|nsdl|uti|aeps|pay\s*out|payment|settle?ment|settelment|withdraw(al)?|kyc|e-?kyc|m\s*-?atm|micro\s*atm|dmt|wallet|irctc|transaction|txn|service|id)?\s*(issue|issu|isu|problem|prob|error|fail(ed)?|not\s+work(ing)?(\s+this)?|no\s+work|ke\s+bare\s+me(in)?)?[\s\!\.\?]*$/iu',
            ],
            // Requests only a person can carry out: activating, cancelling, installing,
            // changing account details. They get the escalation reply and "Raise ticket".
            'escalation_request' => [
                // Package defaults (a type listed here replaces them)
                '/\b(talk to (a\s*)?(human|agent|person|support|executive))\b/iu',
                '/\b(call me|call back|contact me)\b/iu',
                '/\b(escalate|escalation|raise (a\s*)?complaint)\b/iu',
                '/\b(speak to (a\s*)?(manager|supervisor))\b/iu',
                '/\b(need (a\s*)?(human|real person))\b/iu',
                '/\b(this (is\s*)?(not helping|useless))\b/iu',
                // "activate / enable / act ... service / id"; not "how to activate" (a question)
                // and not "not active" / "active nahi" (a status the user reports)
                '/^(?!.*\b(how|kaise|kese|kaisa|kse)\b)(?!.*\b(not|nahi|nhi|in)\s*activ)(?!.*\bactiv\w*\s+(nahi|nhi|nai)\b).*\b(activ\w*|actv\w*|act|enable|chalu|start)\b.{0,40}\b(service|services|id|pan|aadh?aa?r\s*pay|adhar\s*pay|aeps|dmt|irctc|m\s*-?atm|ayushman|pmjay|card)\b/iu',
                // "pan service active", "pan card service enable", "payout chalu karo"
                '/^(?!.*\b(how|kaise|kese|not|nahi|nhi)\b).*\b(service|services|id|pan|aadh?aa?r\s*pay|adhar\s*pay|aeps|dmt|irctc|payout)\s+(activ\w*|enable\w*|chalu|chalo)\b(?!\s+(nahi|nhi|nai))/iu',
                '/^(please\s+|plz\s+|ye\s+\w+\s+)?(chalu|chalo)\s+kar\w*/iu',
                // Close, disable or block an ID / account
                '/\b(delete|disable|deactivat\w*|off\s+kar\w*|band\s+kar\w*)\b.{0,30}\b(id|account|merchant|marchant|txn|transaction)\b|\b(id|account|merchant|marchant)\b.{0,30}\b(delete|disable|deactivat\w*|off\s+kar\w*|band\s+kar\w*)\b|\bid\s+block/iu',
                // Asking for an agent / service ID
                '/\b(agent|aajent|ajent)\s*id\b|\bid\s+(chahiye|chaiye|nahi\s+di|nhi\s+di|nahi\s+mili|nhi\s+mili)/iu',
                // Cancel a transaction, token or application (a request, not "was cancelled")
                '/\b(cancel|cancle|cansel|cencil|cancal|rijekt)\b(?!\s+(ho|hua|kiya|kar\s+diya))(?!.*\brefund\w*\s+(not|nahi|nhi|abhi))/iu',
                // Install a device driver / RD service
                '/\b(install\w*|instalation)\b/iu',
                // Forgotten password, change of registered details
                '/\b(password|passwors)\b.{0,40}\b(bhul\w*|forgot\w*|forget|reset)\b|\b(bhul\w*|forgot\w*)\b.{0,40}\b(password|passwors)\b/iu',
                '/\b(change|edit|update)\w*\b.{0,30}\b(mobile|mob|number|no|account|email|name)\b/iu',
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
        // The first category with a matching keyword wins, so specific services come first
        'categories' => [
            'AADHAAR_PAY' => ['aadhaar pay', 'aadhar pay', 'adhar pay'],
            'MATM'        => ['matm', 'm atm', 'm-atm', 'micro atm', 'microatm', 'mini atm'],
            'DEVICE'      => ['rd service', 'driver', 'mantra', 'morpho', 'startek', 'biometric device', 'fingerprint device', 'l1 device'],
            'KYC'         => ['kyc', 'ekyc', 'e-kyc', 'onboarding', 'onboard', 'on boarding', 'onbording', 'unboding', 'registration', 'ragistration', 'rejistration', 'mismatch', 'missmatch', 'mishmatch', 'missmatched', 'name not matching'],
            'PAN'         => ['pan', 'pen card', 'nsdl', 'uti', 'pan card', 'token', 'tokan', 'acknowledgement', 'ack'],
            'IRCTC'       => ['irctc', 'train', 'railway', 'pnr', 'tatkal'],
            'RECHARGE'    => ['recharge', 'topup', 'jio', 'airtel', 'vi', 'vodafone', 'bsnl', 'dth', 'd2h', 'operator'],
            'DMT'         => ['dmt', 'money transfer', 'remitter', 'remittance', 'sender'],
            'BBPS'        => ['bbps', 'bill payment', 'electricity bill', 'bijli'],
            'FASTAG'      => ['fastag'],
            'PMJAY'       => ['ayushman', 'pmjay'],
            'AEPS'        => ['aeps', 'apes', 'withdrawal', 'withdraw', 'withdrawl', 'withdral', 'widrawal', 'withrowal', 'cash deposit', 'deposit', 'deposite', 'balance enquiry', 'mini statement', 'fingerprint', 'biometric', '2fa', 'two step'],
            'PAYOUT'      => ['payout', 'pay out', 'settlement', 'settelment', 'settle', 'settel', 'fund transfer', 'cash out', 'imps', 'neft', 'rtgs', 'beneficiary'],
            'WALLET'      => ['add fund', 'add money', 'load wallet', 'zaakpay', 'wallet'],
        ],
    ],

    // Replies follow the user's language: English, Hindi (Devanagari) or
    // Hinglish (Hindi in Latin letters). The knowledge base has no Hinglish
    // column, so Hinglish questions get the Hindi KB answer.
    'locales' => [
        'default'   => 'en',
        'available' => [
            'en'      => ['label' => 'English'],
            'hi'      => ['label' => 'हिंदी', 'script' => 'Devanagari'],
            'hi-Latn' => [
                'label'    => 'Hinglish',
                'patterns' => [
                    '/\b(kaise|kaisa|kaisi|kya|kyu|kyun|kyon|nahi|nahin|nhi|hai|hain|karen|karein|karna|karu|kare|karo|mera|meri|mere|mujhe|hamara|aapka|paisa|paise|wapas|aaya|aayi|gaya|gayi|raha|rahi|hua|hui|kab|kahan|kitna|abhi|bhi|aur|lekin|se|ko|ka|ki|ke|par|kiya|diya|gya|gyi|rha|rhi|rhe|kar|kro|krna|krne|kat|hoga|wala|wali|ji|chahiye|mujhko|mein|nhai|jo)\b/iu',
                ],
                'fallback' => 'hi',
            ],
        ],
    ],

    'responses' => [
        'empty' => [
            'en'      => 'Please type your question.',
            'hi'      => 'कृपया अपना सवाल लिखिए।',
            'hi-Latn' => 'Kripya apna sawaal likhiye.',
        ],
        'noise' => [
            'en'      => "Sorry, I didn't catch that. Could you describe the problem?",
            'hi'      => 'माफ़ कीजिए, मैं समझ नहीं पाई। कृपया अपनी समस्या बताइए।',
            'hi-Latn' => 'Maaf kijiye, main samajh nahi paayi. Kripya apni samasya bataiye.',
        ],
        'greeting' => [
            'en'      => 'Hi! What can I help you with today?',
            'hi'      => 'नमस्ते! बताइए, मैं आपकी क्या मदद कर सकती हूँ?',
            'hi-Latn' => 'Namaste! Bataiye, main aapki kya madad kar sakti hoon?',
        ],
        'vague' => [
            'en'      => 'Could you tell me a bit more? For example, the service (AEPS, PAN, recharge…) and the exact message you see.',
            'hi'      => 'थोड़ा और बताइए: कौन सी सेवा (AEPS, PAN, रिचार्ज…) और स्क्रीन पर कौन सा संदेश दिख रहा है?',
            'hi-Latn' => 'Thoda aur bataiye: kaun si service (AEPS, PAN, recharge…) aur screen par kaun sa message dikh raha hai?',
        ],
        'abuse_severe' => [
            'en'      => "I'm here to help with technical issues. Please keep the conversation respectful.",
            'hi'      => 'मैं तकनीकी समस्याओं में मदद के लिए हूँ। कृपया सम्मानजनक भाषा का उपयोग करें।',
            'hi-Latn' => 'Main technical samasyaon mein madad ke liye hoon. Kripya sammaan se baat karein.',
        ],
        'escalation' => [
            'en'      => 'Sure, I can pass this to our support team. Use the button below to raise a ticket, or call our helpline for urgent help.',
            'hi'      => 'ज़रूर, मैं इसे हमारी सपोर्ट टीम तक पहुँचा सकती हूँ। टिकट बनाने के लिए नीचे दिया बटन दबाइए, या तुरंत मदद के लिए हमारी हेल्पलाइन पर कॉल करें।',
            'hi-Latn' => 'Zaroor, main ise hamari support team tak pahuncha sakti hoon. Ticket banane ke liye neeche diya button dabaiye, ya turant madad ke liye hamari helpline par call karein.',
        ],
        'escalate_action' => [
            'en'      => 'Raise ticket',
            'hi'      => 'टिकट बनाएं',
            'hi-Latn' => 'Ticket banayein',
        ],
        'tool_not_found' => [
            'en'      => "I couldn't find that reference in your account. Please check the number and try again.",
            'hi'      => 'आपके खाते में यह रेफ़रेंस नहीं मिला। कृपया नंबर जाँचकर फिर से कोशिश करें।',
            'hi-Latn' => 'Aapke account mein yeh reference nahi mila. Kripya number check karke phir se koshish karein.',
        ],
        'tool_failed' => [
            'en'      => "I couldn't check that right now. Please try again in a few minutes.",
            'hi'      => 'अभी इसे जाँच नहीं पाई। कृपया कुछ मिनट बाद फिर से कोशिश करें।',
            'hi-Latn' => 'Abhi ise check nahi kar paayi. Kripya kuch minute baad phir se koshish karein.',
        ],
        'unknown' => [
            'en'      => "Sorry, I don't have an answer for that yet. Our support team can look into it for you.",
            'hi'      => 'माफ़ कीजिए, इस बारे में अभी मेरे पास जानकारी नहीं है। हमारी सपोर्ट टीम इसमें आपकी मदद कर सकती है।',
            'hi-Latn' => 'Maaf kijiye, is baare mein abhi mere paas jaankari nahi hai. Hamari support team ismein aapki madad kar sakti hai.',
        ],
        'unknown_category' => [
            'en'      => "Sorry, I don't have an answer for this **:category** query yet. Our support team can look into it for you.",
            'hi'      => 'माफ़ कीजिए, **:category** से जुड़े इस सवाल की जानकारी अभी मेरे पास नहीं है। हमारी सपोर्ट टीम इसमें आपकी मदद कर सकती है।',
            'hi-Latn' => 'Maaf kijiye, **:category** se jude is sawaal ki jaankari abhi mere paas nahi hai. Hamari support team ismein aapki madad kar sakti hai.',
        ],
        'loop_exit' => [
            'en'      => "I've shared everything I have on this. Our support team can take it from here.",
            'hi'      => 'इस बारे में मेरे पास जितनी जानकारी थी, मैंने बता दी है। आगे हमारी सपोर्ट टीम आपकी मदद कर सकती है।',
            'hi-Latn' => 'Is baare mein mere paas jitni jaankari thi, maine bata di hai. Aage hamari support team aapki madad kar sakti hai.',
        ],
    ],
];
