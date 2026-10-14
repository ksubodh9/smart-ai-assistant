<?php

namespace Subodh\SmartAiAssistant\Knowledge;

use Illuminate\Database\Eloquent\Builder;
use Subodh\SmartAiAssistant\Core\Contracts\PassageSource;
use Subodh\SmartAiAssistant\Core\Data\Passage;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Models\DocumentChunk;

/**
 * Passages of ingested documents (smart-ai:ingest) for a message.
 *
 * The message is reduced to search words (no stopwords, no very short Latin
 * words). The database narrows the candidates: MySQL/MariaDB through the
 * FULLTEXT index, with a LIKE search when that finds nothing (InnoDB's parser
 * splits Devanagari words at vowel signs); other databases through LIKE.
 * Each candidate is then scored in PHP as the share of search words it
 * contains, so the score means the same on every database and min_score is
 * a plain fraction (0.5 = half of the words).
 *
 * Only active versions of the configured domain whose audience is allowed
 * are searched, in SQL.
 */
class DocumentKnowledgeSource implements PassageSource
{
    /** Common English words that say nothing about the topic; hosts add their own */
    public const DEFAULT_STOPWORDS = [
        'about', 'after', 'again', 'also', 'and', 'any', 'are', 'can', 'could', 'did', 'does', 'doing', 'for',
        'from', 'get', 'got', 'had', 'has', 'have', 'help', 'hello', 'how', 'into', 'its', 'just', 'me', 'more',
        'much', 'not', 'now', 'please', 'should', 'some', 'tell', 'than', 'thank', 'thanks', 'that', 'the',
        'their', 'them', 'then', 'there', 'these', 'they', 'this', 'what', 'when', 'where', 'which', 'who',
        'why', 'will', 'with', 'would', 'you', 'your', 'was', 'were', 'been', 'being', 'want', 'need', 'our',
    ];

    /** Candidates scored in PHP per search */
    private const CANDIDATES = 100;

    /** @var array<string, true> */
    private array $stopwords;

    /**
     * @param  list<string>  $stopwords  Added to DEFAULT_STOPWORDS
     */
    public function __construct(
        private readonly string $service,
        private readonly float $minScore = 0.4,
        array $stopwords = [],
    ) {
        $this->stopwords = array_fill_keys(
            array_map(fn ($word) => mb_strtolower((string) $word), array_merge(self::DEFAULT_STOPWORDS, $stopwords)),
            true
        );
    }

    public function search(string $text, array $audiences, int $limit = 3): array
    {
        $terms = $this->terms($text);

        if ($terms === [] || $audiences === [] || $limit < 1) {
            return [];
        }

        $candidates = $this->usesFullText() ? $this->fullTextCandidates($terms, $audiences) : collect();

        if ($candidates->isEmpty()) {
            $candidates = $this->likeCandidates($terms, $audiences);
        }

        $passages = [];

        foreach ($candidates as $chunk) {
            $score = $this->score($terms, $this->words(($chunk->heading ?? '') . ' ' . $chunk->content));

            if ($score >= $this->minScore) {
                $passages[] = new Passage(
                    id: (int) $chunk->id,
                    documentId: (int) $chunk->document_id,
                    title: (string) $chunk->title,
                    version: (int) $chunk->version,
                    heading: $chunk->heading,
                    content: (string) $chunk->content,
                    score: round($score, 3),
                );
            }
        }

        // Most words first; then the database's relevance order (kept by the stable sort)
        usort($passages, fn (Passage $a, Passage $b) => $b->score <=> $a->score);

        return array_slice($passages, 0, $limit);
    }

    /**
     * The message's distinct search words, lower-cased.
     *
     * @return list<string>
     */
    public function terms(string $text): array
    {
        $terms = [];

        foreach ($this->words($text) as $word) {
            $latin = preg_match('/^[a-z0-9]+$/', $word) === 1;

            // Latin words of 1–2 letters ("is", "to") carry no topic; other scripts can be that short
            if (isset($this->stopwords[$word]) || ($latin && strlen($word) < 3 && !ctype_digit($word)) || mb_strlen($word) < 2) {
                continue;
            }

            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        // Letters with their combining marks (Devanagari vowel signs), and digits
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $words
     */
    private function score(array $terms, array $words): float
    {
        $words = array_fill_keys($words, true);
        $found = 0;

        foreach ($terms as $term) {
            // "refund" also finds "refunds", and "refunds" finds "refund"
            if (isset($words[$term]) || isset($words[$term . 's']) || (str_ends_with($term, 's') && isset($words[substr($term, 0, -1)]))) {
                $found++;
            }
        }

        return $found / count($terms);
    }

    private function base(array $audiences): Builder
    {
        return DocumentChunk::query()
            ->join('smart_ai_documents as documents', 'documents.id', '=', 'smart_ai_document_chunks.document_id')
            ->where('documents.service', $this->service)
            ->where('documents.status', Document::ACTIVE)
            ->whereIn('documents.audience', $audiences)
            ->select([
                'smart_ai_document_chunks.id', 'smart_ai_document_chunks.document_id', 'smart_ai_document_chunks.heading',
                'smart_ai_document_chunks.content', 'documents.title', 'documents.version',
            ]);
    }

    private function usesFullText(): bool
    {
        return in_array(DocumentChunk::query()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  list<string>  $terms
     */
    private function fullTextCandidates(array $terms, array $audiences)
    {
        $match = 'MATCH(smart_ai_document_chunks.heading, smart_ai_document_chunks.content) AGAINST (? IN NATURAL LANGUAGE MODE)';
        $query = implode(' ', $terms);

        return $this->base($audiences)
            ->selectRaw("{$match} AS relevance", [$query])
            ->whereRaw($match, [$query])
            ->orderByDesc('relevance')
            ->limit(self::CANDIDATES)
            ->get();
    }

    /**
     * Passages containing any of the words, in document order.
     *
     * @param  list<string>  $terms
     */
    private function likeCandidates(array $terms, array $audiences)
    {
        return $this->base($audiences)
            ->where(function (Builder $query) use ($terms) {
                foreach ($terms as $term) {
                    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
                    $query->orWhereRaw("LOWER(smart_ai_document_chunks.content) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(smart_ai_document_chunks.heading) LIKE ? ESCAPE '!'", [$pattern]);
                }
            })
            ->orderBy('smart_ai_document_chunks.document_id')
            ->orderBy('smart_ai_document_chunks.position')
            ->limit(self::CANDIDATES)
            ->get();
    }
}
