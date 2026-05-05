<?php

class SEOPC_Internal_Links {

    public function suggest_for_post($post_id, $limit = 5) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        $current_title_tokens = $this->tokenize(get_the_title($post_id));
        $current_url = get_permalink($post_id);

        $candidates = get_posts([
            'post_type' => get_post_type($post_id),
            'post_status' => 'publish',
            'posts_per_page' => 40,
            'post__not_in' => [$post_id],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $suggestions = [];
        foreach ($candidates as $candidate) {
            $tokens = $this->tokenize($candidate->post_title);
            $overlap = array_intersect($current_title_tokens, $tokens);
            $score = count($overlap);
            if ($score < 1) {
                continue;
            }
            $candidate_url = get_permalink($candidate->ID);
            if ($candidate_url === $current_url) {
                continue;
            }
            $suggestions[] = [
                'post_id' => $candidate->ID,
                'title' => $candidate->post_title,
                'url' => $candidate_url,
                'anchor' => $candidate->post_title,
                'score' => $score,
            ];
        }

        usort($suggestions, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($suggestions, 0, $limit);
    }

    private function tokenize($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $parts = preg_split('/[^a-z0-9]+/i', $text);
        $stopwords = ['the','and','for','with','from','into','your','you','this','that','have','has','are','was','were','but','not','our','out','how','why','what'];
        return array_values(array_filter(array_unique($parts), function ($part) use ($stopwords) {
            return strlen($part) > 2 && !in_array($part, $stopwords, true);
        }));
    }
}
