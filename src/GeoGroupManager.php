<?php
/**
 * Manages geo sets and campaign→geo assignments.
 * Persists to data/geo_sets.json and data/campaign_geo.json.
 */

require_once __DIR__ . '/config.php';

class GeoGroupManager {

    /**
     * Load geo sets, seeding from GEO_PRESETS if file doesn't exist.
     */
    public function getGeoSets(): array {
        if (file_exists(GEO_SETS_FILE)) {
            $data = json_decode(file_get_contents(GEO_SETS_FILE), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Seed from presets
        $sets = GEO_PRESETS;
        $this->saveGeoSets($sets);
        return $sets;
    }

    /**
     * Persist geo sets to disk.
     */
    public function saveGeoSets(array $sets): void {
        $dir = dirname(GEO_SETS_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents(GEO_SETS_FILE, json_encode($sets, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Upsert a single geo set.
     */
    public function upsertGeoSet(string $key, string $label, array $countries): void {
        $sets = $this->getGeoSets();
        $sets[$key] = ['label' => $label, 'countries' => $countries];
        $this->saveGeoSets($sets);
    }

    /**
     * Delete a geo set and remove any campaign assignments referencing it.
     */
    public function deleteGeoSet(string $key): void {
        $sets = $this->getGeoSets();
        unset($sets[$key]);
        $this->saveGeoSets($sets);

        // Clean up assignments
        $assignments = $this->getAssignments();
        $changed = false;
        foreach ($assignments as $campId => $geoKey) {
            if ($geoKey === $key) {
                unset($assignments[$campId]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveAssignments($assignments);
        }
    }

    /**
     * Load campaign→geo assignments.
     * @return array { "campaignId": "geo_set_key", ... }
     */
    public function getAssignments(): array {
        if (file_exists(CAMPAIGN_GEO_FILE)) {
            $data = json_decode(file_get_contents(CAMPAIGN_GEO_FILE), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    /**
     * Bulk merge campaign→geo assignments.
     * @param array $map { "campaignId": "geo_set_key", ... }
     */
    public function assign(array $map): void {
        $assignments = $this->getAssignments();
        foreach ($map as $campId => $geoKey) {
            $assignments[(string)$campId] = $geoKey;
        }
        $this->saveAssignments($assignments);
    }

    /**
     * Remove geo assignments for the given campaign IDs.
     */
    public function unassign(array $ids): void {
        $assignments = $this->getAssignments();
        foreach ($ids as $id) {
            unset($assignments[(string)$id]);
        }
        $this->saveAssignments($assignments);
    }

    private function saveAssignments(array $assignments): void {
        $dir = dirname(CAMPAIGN_GEO_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents(CAMPAIGN_GEO_FILE, json_encode($assignments, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
