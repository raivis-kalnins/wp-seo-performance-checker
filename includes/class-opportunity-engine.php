<?php

class SEOPC_Opportunity_Engine {

    public function build_page_opportunities($search_pages, $limit = 10) {
        $rows = [];
        foreach ((array) $search_pages as $page) {
            $impressions = (float) ($page['impressions'] ?? 0);
            $ctr = (float) ($page['ctr'] ?? 0);
            $position = (float) ($page['position'] ?? 0);
            if ($impressions <= 0) {
                continue;
            }
            $visibility_factor = min(1, $impressions / 5000);
            $ctr_gap = max(0, 0.08 - $ctr) / 0.08;
            $position_factor = ($position >= 5 && $position <= 20) ? 1 - (abs(12.5 - $position) / 12.5) : 0.2;
            $score = round((($visibility_factor * 0.45) + ($ctr_gap * 0.35) + ($position_factor * 0.20)) * 100, 1);
            $rows[] = [
                'page' => $page['page'] ?? '',
                'impressions' => $impressions,
                'clicks' => (float) ($page['clicks'] ?? 0),
                'ctr' => $ctr,
                'position' => $position,
                'opportunity_score' => $score,
            ];
        }
        usort($rows, function ($a, $b) {
            return $b['opportunity_score'] <=> $a['opportunity_score'];
        });
        return array_slice($rows, 0, $limit);
    }
}
