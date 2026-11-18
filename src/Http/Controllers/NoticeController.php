<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Contracts\StatusNoticeSource;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\Notice;
use Subodh\SmartAiAssistant\Notices\NoticeFormatter;
use Subodh\SmartAiAssistant\Support\Locales;

/**
 * GET /smart-assistant/notices: the active known issues, shown when the
 * widget opens. {protocol, notices: [{id, service, blocks[]}]}, one text
 * block per notice in the reply language. Never stores anything.
 */
class NoticeController extends Controller
{
    public function index(Request $request, UserContextResolver $users, StatusNoticeSource $source, NoticeFormatter $formatter, Locales $locales)
    {
        $request->validate(['locale' => ['nullable', 'string', 'max:20']]);

        if (!config('smart-ai-assistant.capabilities.status_notices', true)) {
            return response()->json(['protocol' => 1, 'notices' => []]);
        }

        $locale = $locales->choose($request->query('locale'), null, null, $users->resolve($request)->locale);
        $max = max(0, (int) config('smart-ai-assistant.status_notices.max_shown', 3));

        $notices = array_map(function (Notice $notice) use ($formatter, $locales, $locale) {
            $answer = $locales->pick($formatter->answers([$notice], 'status_notice'), $locale);

            return [
                'id'      => $notice->id,
                'service' => $notice->service,
                'blocks'  => $answer === null ? [] : [[
                    'type' => 'text', 'format' => 'basic', 'locale' => $answer['locale'], 'text' => $answer['text'],
                ]],
            ];
        }, array_slice($source->active(), 0, $max));

        return response()->json(['protocol' => 1, 'notices' => $notices]);
    }
}
