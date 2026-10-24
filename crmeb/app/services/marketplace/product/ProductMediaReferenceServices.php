<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\dao\marketplace\product\PlatformProductMediaDao;
use app\dao\marketplace\product\ProductMediaReferenceDao;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;

/** Shared final media references within the existing product writer transaction. */
final class ProductMediaReferenceServices
{
    public function __construct(
        private ProductMediaReferenceDao $references,
        private MerchantAttachmentStorage $storage,
        private EmployeeAuthServices $authentication
    ) {}

    /** The actual legacy save derives its main image from the first slider entry. */
    public function legacyReferences(array $data): array
    {
        $out = [];
        $slider = $data['slider_image'] ?? [];
        if (!is_array($slider) || !array_is_list($slider)) self::invalid();
        if ($slider !== []) self::append($out, 'image', $slider[0], 'image');
        foreach ($slider as $index => $path) self::append($out, 'image', $path, 'slider_image.' . $index);
        $attrs = $data['attrs'] ?? [];
        $items = $data['items'] ?? [];
        if (!is_array($attrs) || !is_array($items)) self::invalid();
        foreach ($attrs as $index => $sku) {
            if (!is_array($sku)) self::invalid();
            if (isset($sku['pic'])) self::append($out, 'image', $sku['pic'], 'attrs.' . $index . '.pic');
        }
        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_array($item['detail'] ?? [])) self::invalid();
            foreach ($item['detail'] ?? [] as $optionIndex => $option) {
                if (is_array($option) && ($option['pic'] ?? '') !== '') {
                    self::append($out, 'image', $option['pic'], 'items.' . $index . '.detail.' . $optionIndex . '.pic');
                }
            }
        }
        foreach (['recommend_image' => 'image', 'video_link' => 'video'] as $field => $kind) {
            if (($data[$field] ?? '') !== '') self::append($out, $kind, $data[$field], $field);
        }
        if (isset($data['description'])) {
            if (!is_string($data['description'])) self::invalid();
            foreach (MerchantProductInput::typedHtmlMedia($data['description']) as $reference) {
                $reference['field'] = 'description';
                $out[] = $reference;
            }
        }
        return $out;
    }

    /** Consume normalized definitions without changing any persisted DTO or SKU order. */
    public function merchantReferences(array $input, array $definitions): array
    {
        $out = [];
        self::append($out, 'image', $input['root']['image'], 'image');
        foreach ($input['slider'] as $index => $path) self::append($out, 'image', $path, 'slider_image.' . $index);
        foreach ($definitions['items'] as $index => $item) {
            foreach ($item['detail'] as $optionIndex => $option) {
                if (($option['pic'] ?? '') !== '') {
                    self::append($out, 'image', $option['pic'], 'items.' . $index . '.detail.' . $optionIndex . '.pic');
                }
            }
        }
        foreach ($definitions['skus'] as $index => $sku) {
            self::append($out, 'image', $sku['row']['image'], 'attrs.' . $index . '.pic');
        }
        // The existing preflight also qualified preset/unused option media before
        // definitions were filtered. Retain that complete original coverage.
        foreach ($definitions['media'] as $index => $path) {
            self::append($out, 'image', $path, 'definition_media.' . $index);
        }
        if ($input['root']['video_link'] !== '') {
            self::append($out, 'video', $input['root']['video_link'], 'video_link');
        }
        foreach (MerchantProductInput::typedHtmlMedia($input['description']) as $reference) {
            $reference['field'] = 'description';
            $out[] = $reference;
        }
        return $out;
    }

    private static function append(array &$out, string $kind, mixed $path, string $field): void
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || preg_match('//u', $path) !== 1) self::invalid();
        $out[] = ['kind' => $kind, 'path' => $path, 'field' => $field];
    }

    public function assertForProduct(int $productIdOrZero, array $references): array
    {
        return $this->assertReferences(null, $productIdOrZero, $references);
    }

    public function assertForMerchant(MerchantContext $ctx, int $productIdOrZero, array $references): array
    {
        return $this->assertReferences($ctx, $productIdOrZero, $references);
    }

    private function context(?MerchantContext $ctx, int $id): array
    {
        if ($id < 0 || $id > MerchantProductInput::ROOT_MAX) self::invalid();
        if ($ctx === null) {
            $pdo = LegacyProductWriteServices::transaction();
            $owner = LegacyProductWriteServices::mediaOwner($id);
            return [Db::connect(), $pdo, $owner];
        }
        $ctx->require('product.write');
        $this->authentication->authorizeAction($ctx, 'product.write', true);
        $connection = Db::connect();
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) self::unavailable();
        if ($id > 0) {
            $query = Db::name('store_product')->where('id', $id)->where('mer_id', $ctx->merId)->field(['id', 'mer_id'])->lock(true);
            if ($query->getConnection() !== $connection || $query->getConnection()->getPdo() !== $pdo) self::unavailable();
            $row = $query->find();
            if (!is_array($row) || self::integer($row['id'], 1, MerchantProductInput::ROOT_MAX) !== $id
                || self::integer($row['mer_id'], 1, 4294967295) !== $ctx->merId) self::unavailable();
        }
        return [$connection, $pdo, $ctx->merId];
    }

    private function sameContext(?MerchantContext $ctx, int $id, array $expected): void
    {
        if ($this->context($ctx, $id) !== $expected) self::unavailable();
    }

    private function assertReferences(?MerchantContext $ctx, int $id, array $references): array
    {
        if (!array_is_list($references)) self::invalid();
        $state = $this->context($ctx, $id);
        [$connection, $pdo, $owner] = $state;
        $proofs = [];
        $qualified = [];
        foreach ($references as $reference) {
            $this->sameContext($ctx, $id, $state);
            if (!is_array($reference) || !in_array($reference['kind'] ?? null, ['image', 'video', 'audio', 'unresolved'], true)
                || !is_string($reference['path'] ?? null)) self::invalid();
            // DOM attributes have already been decoded once by typedHtmlMedia.
            // Ordinary DTO URLs are opaque; decoding either here can change the object.
            $decoded = $reference['path'];
            if ($decoded === '' || str_contains($decoded, "\0") || preg_match('//u', $decoded) !== 1) self::invalid();
            $key = $reference['kind'] . "\0" . $decoded;
            if (isset($qualified[$key])) {
                $proofs[] = ['reference' => $reference] + $qualified[$key];
                continue;
            }
            $candidates = $this->candidates($decoded);
            $rows = $this->references->lockNative14($pdo, $owner, $candidates);
            if (count($rows) !== 1) self::invalid();
            $native = $rows[0];
            $this->native($native, $owner);
            $this->category($connection, $pdo, $owner, $native);
            $object = $ctx === null ? $this->storage->locateForProduct($native, $id) : $this->storage->locate($native, $ctx);
            $selected = $this->selectedCanonical($object, $decoded);
            $physical = $this->storage->assertTypedReference($native, $object, $reference['kind'], $selected);
            $this->sameContext($ctx, $id, $state);
            if (!is_array($physical) || !in_array($physical['resolved_kind'] ?? null, ['image', 'video', 'audio'], true)
                || ($reference['kind'] !== 'unresolved' && $physical['resolved_kind'] !== $reference['kind'])
                || ($physical['identity'] ?? null) !== $this->storage->identity($object)
                || ($physical['selected']['canonical'] ?? null) !== $selected
                || ($physical['native14_sha256'] ?? null) !== hash('sha256', MerchantProductInput::json($native))) self::unavailable();
            foreach (['original', 'selected'] as $part) {
                if (!is_int($physical[$part]['bytes'] ?? null) || $physical[$part]['bytes'] < 1
                    || !is_string($physical[$part]['sha256'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $physical[$part]['sha256']) !== 1) self::unavailable();
            }
            if ($physical['original']['bytes'] !== self::integer($native['att_size'], 1, PHP_INT_MAX)) self::unavailable();
            $again = $this->references->lockNative14($pdo, $owner, $candidates);
            if ($again !== [$native]) self::unavailable();
            $againObject = $ctx === null ? $this->storage->locateForProduct($native, $id) : $this->storage->locate($native, $ctx);
            if ($this->objectIdentity($againObject) !== $this->objectIdentity($object)
                || $this->selectedCanonical($againObject, $decoded) !== $selected) self::unavailable();
            $this->sameContext($ctx, $id, $state);
            $qualified[$key] = ['native14' => $native, 'physical' => $physical];
            $proofs[] = ['reference' => $reference] + $qualified[$key];
        }
        $this->sameContext($ctx, $id, $state);
        return $proofs;
    }

    /** Invert the existing DTO producer only, never an arbitrary origin/basename. */
    private function candidates(string $decoded): array
    {
        $site = sys_config('site_url');
        if (!is_string($site)) self::unavailable();
        $candidates = [$decoded];
        $origins = [$site];
        if (str_starts_with($site, 'http:')) $origins[] = 'https:' . substr($site, 5);
        foreach ($origins as $origin) {
            if ($origin !== '' && str_starts_with($decoded, $origin . '/')) {
                $relative = substr($decoded, strlen($origin));
                $display = set_file_url($relative, $site);
                if ($display === $decoded || (str_starts_with($display, 'http:') && 'https:' . substr($display, 5) === $decoded)) {
                    $candidates[] = $relative;
                }
            }
        }
        if (str_starts_with($decoded, 'https:')) $candidates[] = 'http:' . substr($decoded, 6);
        return array_values(array_unique($candidates));
    }

    private function selectedCanonical(array $object, string $decoded): string
    {
        $matches = [];
        foreach ($object['canonical'] as $canonical) {
            $variant = $object;
            $variant['canonical'] = [$canonical];
            if (in_array($decoded, $this->storage->aliases([$variant]), true)) $matches[] = $canonical;
        }
        // Cloud canonical URLs are aliases for one storage/key; local variants
        // are distinct files and the selected one must be unambiguous.
        if ($matches === [] || ($object['type'] === 1 && count($matches) !== 1)) self::invalid();
        return $matches[0];
    }

    private function objectIdentity(array $object): array
    {
        $files = [];
        foreach ($object['files'] as $file) {
            $facts = [];
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) $facts[$key] = $file['stat'][$key];
            $files[] = ['path' => $file['path'], 'canonical' => $file['canonical'], 'stat' => $facts];
        }
        return ['identity' => $this->storage->identity($object), 'type' => $object['type'], 'canonical' => $object['canonical'], 'files' => $files];
    }

    private function native(array $row, int $owner): void
    {
        if (array_keys($row) !== PlatformProductMediaDao::ATTACHMENT_FIELDS
            || self::integer($row['mer_id'], 1, 4294967295) !== $owner
            || self::integer($row['module_type'], 0, 255) !== 1) self::unavailable();
        self::integer($row['att_id'], 1, 2147483647);
        self::integer($row['pid'], 0, 2147483647);
        self::integer($row['time'], 0, 2147483647);
        self::integer($row['image_type'], 1, 7);
        self::integer($row['type'], 0, 1);
        self::integer($row['att_size'], 1, PHP_INT_MAX);
        foreach (['name'=>100, 'att_dir'=>200, 'satt_dir'=>200, 'att_size'=>30, 'att_type'=>30, 'real_name'=>255, 'scan_token'=>32] as $field => $maximum) {
            if (!is_string($row[$field]) || str_contains($row[$field], "\0") || preg_match('//u', $row[$field]) !== 1
                || mb_strlen($row[$field], 'UTF-8') > $maximum) self::unavailable();
        }
    }

    private function category(object $connection, PDO $pdo, int $owner, array $attachment): void
    {
        $id = self::integer($attachment['pid'], 0, 2147483647);
        if ($id === 0) return;
        $query = Db::name('system_attachment_category')->where('id', $id)->where('mer_id', $owner)->lock(true);
        if ($query->getConnection() !== $connection || $query->getConnection()->getPdo() !== $pdo) self::unavailable();
        $row = $query->find();
        if (!is_array($row) || array_keys($row) !== ['id', 'pid', 'name', 'enname', 'type', 'mer_id']
            || self::integer($row['id'], 1, 2147483647) !== $id
            || self::integer($row['mer_id'], 1, 4294967295) !== $owner
            || self::integer($row['type'], 0, 1) !== self::integer($attachment['type'], 0, 1)) self::unavailable();
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && strlen($value) <= strlen((string)$maximum)
            && (strlen($value) < strlen((string)$maximum) || strcmp($value, (string)$maximum) <= 0)) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::unavailable();
        return $value;
    }

    private static function invalid(): never { throw new HttpException(422, '商品素材不可用'); }
    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
