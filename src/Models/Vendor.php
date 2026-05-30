<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a vendor in the global vendor library (eim_vendors).
 *
 * Vendors can be linked to budget line items and menu items. The vendor's
 * category drives the category displayed on those records — line items and
 * menu items no longer carry their own category field.
 */
final class Vendor
{
    public function __construct(
        public readonly int    $id,
        public readonly string $companyName,
        public readonly string $contactName,
        public readonly string $streetAddress,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $websiteUrl,
        public readonly string $notes,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /** @return self|null */
    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::vendorsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns a map of id → Vendor for the given IDs. Missing IDs are omitted.
     *
     * @param  int[]          $ids
     * @return array<int,self>
     */
    public static function findMany(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $table        = DatabaseManager::vendorsTable();
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders})", ...$ids) // phpcs:ignore
        );

        $map = [];
        foreach ($rows ?? [] as $row) {
            $vendor       = self::fromRow($row);
            $map[$vendor->id] = $vendor;
        }
        return $map;
    }

    /** @return self[] */
    public static function listForAdmin(
        string $query  = '',
        string $sort   = 'company_name',
        string $order  = 'asc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::vendorsTable();
        $allowed  = ['company_name', 'contact_name', 'email', 'website_url'];
        $sortCol  = in_array($sort, $allowed, true) ? $sort : 'company_name';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $orderBy  = "ORDER BY {$sortCol} {$orderSql}, company_name ASC"; // phpcs:ignore

        if ($query === '') {
            $rows = $wpdb->get_results("SELECT * FROM {$table} {$orderBy}"); // phpcs:ignore
            return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        }

        $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';

        switch ($field) {
            case 'company_name':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(company_name) LIKE %s {$orderBy}", $like
                );
                break;
            case 'email':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(email) LIKE %s {$orderBy}", $like
                );
                break;
            case 'phone':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(phone) LIKE %s {$orderBy}", $like
                );
                break;
            case 'website_url':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(website_url) LIKE %s {$orderBy}", $like
                );
                break;
            case 'address':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table}
                     WHERE LOWER(street_address) LIKE %s
                        OR LOWER(city) LIKE %s
                        OR LOWER(state) LIKE %s
                        OR LOWER(zip_code) LIKE %s
                     {$orderBy}",
                    $like, $like, $like, $like
                );
                break;
            case 'contact_name':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(contact_name) LIKE %s {$orderBy}", $like
                );
                break;
            default:
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table}
                     WHERE LOWER(company_name) LIKE %s
                        OR LOWER(contact_name) LIKE %s
                        OR LOWER(email) LIKE %s
                        OR LOWER(phone) LIKE %s
                        OR LOWER(website_url) LIKE %s
                        OR LOWER(street_address) LIKE %s
                        OR LOWER(city) LIKE %s
                     {$orderBy}",
                    $like, $like, $like, $like, $like, $like, $like
                );
        }

        $rows = $wpdb->get_results($sql); // phpcs:ignore
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Autocomplete search — returns up to $limit vendors whose company name
     * contains the query. Returns plain arrays suitable for JSON encoding.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 10): array
    {
        global $wpdb;

        $query = trim($query);
        if (mb_strlen($query) < 1) {
            return [];
        }

        $table = DatabaseManager::vendorsTable();
        $like  = '%' . $wpdb->esc_like($query) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE company_name LIKE %s ORDER BY company_name ASC LIMIT %d",
                $like,
                $limit
            )
        );

        $vendorIds    = array_map(static fn(object $row): int => (int) $row->id, $rows ?? []);
        $catsByVendor = Category::forEntities('vendor', $vendorIds);

        return array_map(static function (object $row) use ($catsByVendor): array {
            $cats = $catsByVendor[(int) $row->id] ?? [];
            $categoryLabels = array_map(
                static fn(Category $cat): string => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                $cats
            );

            return [
                'id'           => (int)  $row->id,
                'company_name' =>        $row->company_name,
                'email'        =>        $row->email ?? '',
                'phone'        =>        $row->phone ?? '',
                'website_url'  =>        $row->website_url ?? '',
                'category_label' =>      implode(', ', $categoryLabels),
                'label'        =>        $row->company_name,
            ];
        }, $rows ?? []);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): ?self
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::vendorsTable(), [
            'company_name'   => (string) ($data['company_name']   ?? ''),
            'contact_name'   => (string) ($data['contact_name']   ?? ''),
            'street_address' => (string) ($data['street_address'] ?? ''),
            'city'           => (string) ($data['city']           ?? ''),
            'state'          => (string) ($data['state']          ?? ''),
            'zip_code'       => (string) ($data['zip_code']       ?? ''),
            'email'          => (string) ($data['email']          ?? ''),
            'phone'          => (string) ($data['phone']          ?? ''),
            'website_url'    => (string) ($data['website_url']    ?? ''),
            'notes'          => (string) ($data['notes']          ?? ''),
        ]);

        $created = $result ? self::find((int) $wpdb->insert_id) : null;
        if ($created !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_VENDOR, EimChangeEvent::ADDED, $created);
        }
        return $created;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = [];
        if (array_key_exists('company_name',   $data)) $fields['company_name']   = (string) $data['company_name'];
        if (array_key_exists('contact_name',   $data)) $fields['contact_name']   = (string) $data['contact_name'];
        if (array_key_exists('street_address', $data)) $fields['street_address'] = (string) $data['street_address'];
        if (array_key_exists('city',           $data)) $fields['city']           = (string) $data['city'];
        if (array_key_exists('state',          $data)) $fields['state']          = (string) $data['state'];
        if (array_key_exists('zip_code',       $data)) $fields['zip_code']       = (string) $data['zip_code'];
        if (array_key_exists('email',          $data)) $fields['email']          = (string) $data['email'];
        if (array_key_exists('phone',          $data)) $fields['phone']          = (string) $data['phone'];
        if (array_key_exists('website_url',    $data)) $fields['website_url']    = (string) $data['website_url'];
        if (array_key_exists('notes',          $data)) $fields['notes']          = (string) $data['notes'];

        if (empty($fields)) return true;
        $ok = $wpdb->update(DatabaseManager::vendorsTable(), $fields, ['id' => $id]) !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_VENDOR, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Deletes a vendor and nullifies all references to it.
     *
     * Budget line items and menu items that used this vendor have their vendor_id
     * set to null so they remain intact but become unlinked.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $snapshot = self::find($id);

        $wpdb->update(DatabaseManager::budgetLineItemsTable(), ['vendor_id' => null], ['vendor_id' => $id]);
        $wpdb->update(DatabaseManager::menuItemsTable(),       ['vendor_id' => null], ['vendor_id' => $id]);

        $ok = $wpdb->delete(DatabaseManager::vendorsTable(), ['id' => $id]) !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_VENDOR, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
    }

    public static function count(): int
    {
        global $wpdb;
        $table = DatabaseManager::vendorsTable();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore
    }

    // -------------------------------------------------------------------------
    // Usage queries (for the list table "used in" columns)
    // -------------------------------------------------------------------------

    /**
     * Returns budget plan usage grouped by vendor ID.
     *
     * @param  int[]  $vendorIds
     * @return array<int, array<int, array{id:int, name:string}>>
     */
    public static function budgetUsageForVendors(array $vendorIds): array
    {
        global $wpdb;

        $vendorIds = array_values(array_unique(array_filter(array_map('intval', $vendorIds))));
        if (empty($vendorIds)) {
            return [];
        }

        $liTable    = DatabaseManager::budgetLineItemsTable();
        $planTable  = DatabaseManager::budgetPlansTable();
        $placeholders = implode(', ', array_fill(0, count($vendorIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare( // phpcs:ignore
                "SELECT DISTINCT li.vendor_id, p.id AS plan_id, p.name AS plan_name
                 FROM {$liTable} li
                 INNER JOIN {$planTable} p ON p.id = li.plan_id
                 WHERE li.vendor_id IN ({$placeholders})
                 ORDER BY p.name ASC",
                ...$vendorIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $vid = (int) $row->vendor_id;
            $pid = (int) $row->plan_id;
            if (!isset($grouped[$vid][$pid])) {
                $grouped[$vid][$pid] = ['id' => $pid, 'name' => (string) $row->plan_name];
            }
        }

        foreach ($grouped as $vid => $plans) {
            $grouped[$vid] = array_values($plans);
        }

        return $grouped;
    }

    /**
     * Returns menu item usage grouped by vendor ID.
     *
     * @param  int[]  $vendorIds
     * @return array<int, array<int, array{id:int, label:string, type:string}>>
     */
    public static function menuItemUsageForVendors(array $vendorIds): array
    {
        global $wpdb;

        $vendorIds = array_values(array_unique(array_filter(array_map('intval', $vendorIds))));
        if (empty($vendorIds)) {
            return [];
        }

        $table        = DatabaseManager::menuItemsTable();
        $placeholders = implode(', ', array_fill(0, count($vendorIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare( // phpcs:ignore
                "SELECT id, vendor_id, label, type
                 FROM {$table}
                 WHERE vendor_id IN ({$placeholders})
                 ORDER BY label ASC",
                ...$vendorIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $vid = (int) $row->vendor_id;
            $grouped[$vid][] = [
                'id'    => (int)  $row->id,
                'label' =>        (string) $row->label,
                'type'  =>        (string) $row->type,
            ];
        }

        return $grouped;
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    public function formattedAddress(): string
    {
        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            companyName:          $row->company_name   ?? '',
            contactName:          $row->contact_name   ?? '',
            streetAddress:        $row->street_address ?? '',
            city:                 $row->city           ?? '',
            state:                $row->state          ?? '',
            zipCode:              $row->zip_code       ?? '',
            email:                $row->email          ?? '',
            phone:                $row->phone          ?? '',
            websiteUrl:           $row->website_url    ?? '',
            notes:                $row->notes          ?? '',
            createdAt:            $row->created_at     ?? '',
            updatedAt:            $row->updated_at     ?? '',
        );
    }
}
