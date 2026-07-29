<?php
declare(strict_types=1);

use think\Image;
use think\image\gif\Decoder;
use think\image\gif\Encoder;

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        fail('Unable to create directory: ' . $path);
    }
}

function createSolidImage(string $path, string $type, array $rgb): void
{
    $image = imagecreatetruecolor(120, 80);
    if ($image === false) {
        fail('Unable to create source image');
    }
    $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    imagefill($image, 0, 0, $color);
    if ($type === 'png') {
        imagepng($image, $path, 9);
    } else {
        imagegif($image, $path);
    }
    imagedestroy($image);
}

function imageBytes(array $rgb): string
{
    $image = imagecreatetruecolor(120, 80);
    if ($image === false) {
        fail('Unable to create GIF frame');
    }
    $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    imagefill($image, 0, 0, $color);
    ob_start();
    imagegif($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return $bytes;
}

function createFixtures(string $directory): void
{
    ensureDirectory($directory);
    createSolidImage($directory . '/source.png', 'png', [32, 96, 160]);
    createSolidImage($directory . '/source.gif', 'gif', [32, 96, 160]);
    createSolidImage($directory . '/watermark.png', 'png', [192, 48, 64]);

    $frames = [
        imageBytes([32, 96, 160]),
        imageBytes([48, 160, 96]),
        imageBytes([160, 96, 32]),
    ];
    $encoder = new Encoder($frames, [8, 10, 12], 0, 2, -1, -1, -1, 'bin');
    file_put_contents($directory . '/animated.gif', $encoder->getAnimation());
}

function summarize(string $path): array
{
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        fail('Unable to read output: ' . $path);
    }
    $imageInfo = getimagesize($path);
    if ($imageInfo === false) {
        fail('Unable to read image dimensions: ' . $path);
    }
    $result = [
        'bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'dimensions' => [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
        ],
    ];
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'gif') {
        $result['frames'] = count((new Decoder($bytes))->getFrames());
    }
    return $result;
}

function outputFiles(): array
{
    return [
        'png_thumb' => 'png_thumb.png',
        'png_water' => 'png_water.png',
        'static_gif_thumb' => 'static_gif_thumb.gif',
        'static_gif_water' => 'static_gif_water.gif',
        'animated_gif_thumb' => 'animated_gif_thumb.gif',
        'animated_gif_water' => 'animated_gif_water.gif',
        'text_png_1' => 'text_png_1.png',
        'text_png_5' => 'text_png_5.png',
        'text_png_9' => 'text_png_9.png',
        'text_gif_1' => 'text_gif_1.gif',
        'text_gif_5' => 'text_gif_5.gif',
        'text_gif_9' => 'text_gif_9.gif',
    ];
}

function compareExactOutput(string $baselinePath, string $candidatePath, ?int $expectedFrames = null): array
{
    $baselineBytes = file_get_contents($baselinePath);
    $candidateBytes = file_get_contents($candidatePath);
    if ($baselineBytes === false || $candidateBytes === false) {
        fail('Unable to read exact-comparison inputs');
    }
    $baseline = summarize($baselinePath);
    $candidate = summarize($candidatePath);
    $sameBytes = $baselineBytes === $candidateBytes;
    $sameSha256 = hash_equals($baseline['sha256'], $candidate['sha256']);
    $sameDimensions = $baseline['dimensions'] === $candidate['dimensions'];
    $frameCountPassed = $expectedFrames === null
        || (($baseline['frames'] ?? null) === $expectedFrames
            && ($candidate['frames'] ?? null) === $expectedFrames);

    return [
        'passed' => $sameBytes && $sameSha256 && $sameDimensions && $frameCountPassed,
        'mode' => 'exact_bytes_and_sha256',
        'same_bytes' => $sameBytes,
        'same_sha256' => $sameSha256,
        'same_dimensions' => $sameDimensions,
        'expected_frames' => $expectedFrames,
        'frame_count_passed' => $frameCountPassed,
        'baseline' => $baseline,
        'candidate' => $candidate,
    ];
}

function decodePngPixels(string $path): array
{
    $image = imagecreatefrompng($path);
    if ($image === false) {
        fail('Unable to decode PNG: ' . $path);
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $pixels = [];
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($image, $x, $y);
            if ($rgba === false) {
                imagedestroy($image);
                fail('Unable to read PNG pixel: ' . $path);
            }
            $pixels[] = [
                ($rgba >> 16) & 0xff,
                ($rgba >> 8) & 0xff,
                $rgba & 0xff,
                ($rgba >> 24) & 0x7f,
            ];
        }
    }
    imagedestroy($image);

    return [
        'dimensions' => ['width' => $width, 'height' => $height],
        'pixels' => $pixels,
    ];
}

function comparePixelSamples(array $baseline, array $candidate): array
{
    $pixelCountEqual = count($baseline) === count($candidate);
    $differentPixels = abs(count($baseline) - count($candidate));
    $alphaIdentical = $pixelCountEqual;
    $maxRgbChannelDelta = 0;

    $commonPixelCount = min(count($baseline), count($candidate));
    for ($index = 0; $index < $commonPixelCount; $index++) {
        $pixelDiffers = false;
        for ($channel = 0; $channel < 3; $channel++) {
            $delta = abs($baseline[$index][$channel] - $candidate[$index][$channel]);
            $maxRgbChannelDelta = max($maxRgbChannelDelta, $delta);
            $pixelDiffers = $pixelDiffers || $delta !== 0;
        }
        if ($baseline[$index][3] !== $candidate[$index][3]) {
            $alphaIdentical = false;
            $pixelDiffers = true;
        }
        if ($pixelDiffers) {
            $differentPixels++;
        }
    }

    return [
        'passed' => $pixelCountEqual
            && $differentPixels <= 1
            && $alphaIdentical
            && $maxRgbChannelDelta <= 1,
        'pixel_count_equal' => $pixelCountEqual,
        'different_pixels' => $differentPixels,
        'alpha_identical' => $alphaIdentical,
        'max_rgb_channel_delta' => $maxRgbChannelDelta,
    ];
}

function compareTextPngOutput(string $baselinePath, string $candidatePath): array
{
    $baseline = summarize($baselinePath);
    $candidate = summarize($candidatePath);
    $baselinePixels = decodePngPixels($baselinePath);
    $candidatePixels = decodePngPixels($candidatePath);
    $sameDimensions = $baselinePixels['dimensions'] === $candidatePixels['dimensions'];
    $pixelComparison = comparePixelSamples($baselinePixels['pixels'], $candidatePixels['pixels']);

    return [
        'passed' => $sameDimensions && $pixelComparison['passed'],
        'mode' => 'decoded_pixel_tolerance',
        'same_dimensions' => $sameDimensions,
        'limits' => [
            'different_pixels' => 1,
            'alpha_must_match' => true,
            'max_rgb_channel_delta' => 1,
        ],
        'metrics' => $pixelComparison,
        'baseline' => $baseline,
        'candidate' => $candidate,
    ];
}

function runToleranceSelfTest(): array
{
    $baseline = [
        [10, 20, 30, 0],
        [40, 50, 60, 0],
    ];
    $fixtures = [
        'exact_pixels_pass' => [
            'candidate' => $baseline,
            'expected' => true,
        ],
        'one_pixel_rgb_delta_one_passes' => [
            'candidate' => [[11, 20, 30, 0], [40, 50, 60, 0]],
            'expected' => true,
        ],
        'two_changed_pixels_fail' => [
            'candidate' => [[11, 20, 30, 0], [40, 51, 60, 0]],
            'expected' => false,
        ],
        'rgb_delta_two_fails' => [
            'candidate' => [[12, 20, 30, 0], [40, 50, 60, 0]],
            'expected' => false,
        ],
        'alpha_change_fails' => [
            'candidate' => [[10, 20, 30, 1], [40, 50, 60, 0]],
            'expected' => false,
        ],
    ];

    $cases = [];
    $passed = true;
    foreach ($fixtures as $name => $fixture) {
        $comparison = comparePixelSamples($baseline, $fixture['candidate']);
        $actual = $comparison['passed'];
        $casePassed = $actual === $fixture['expected'];
        $cases[$name] = [
            'passed' => $casePassed,
            'expected_tolerance_result' => $fixture['expected'],
            'actual_tolerance_result' => $actual,
            'metrics' => $comparison,
        ];
        $passed = $passed && $casePassed;
    }

    return [
        'status' => $passed ? 'pass' : 'fail',
        'cases' => $cases,
    ];
}

function isKnownStaticGifWarning(int $severity, string $message, string $file, int $line): bool
{
    $normalizedFile = str_replace('\\', '/', $file);
    return in_array($normalizedFile, [
        '/var/www/crmeb/services/image/gif/Encoder.php',
        '/var/www/vendor/topthink/think-image/src/image/gif/Encoder.php',
    ], true)
        && in_array($severity, [E_NOTICE, E_WARNING], true)
        && $line === 77
        && preg_match('/^Undefined (?:array key|offset):? 0$/', $message) === 1;
}

function runDiagnosticAllowlistSelfTest(): array
{
    $fixtures = [
        'project_warning_allowed' => [E_WARNING, 'Undefined array key 0', '/var/www/crmeb/services/image/gif/Encoder.php', 77, true],
        'vendor_notice_allowed' => [E_NOTICE, 'Undefined offset: 0', '/var/www/vendor/topthink/think-image/src/image/gif/Encoder.php', 77, true],
        'unrelated_path_rejected' => [E_WARNING, 'Undefined array key 0', '/tmp/Encoder.php', 77, false],
        'wrong_severity_rejected' => [E_DEPRECATED, 'Undefined array key 0', '/var/www/crmeb/services/image/gif/Encoder.php', 77, false],
        'wrong_line_rejected' => [E_WARNING, 'Undefined array key 0', '/var/www/crmeb/services/image/gif/Encoder.php', 78, false],
        'partial_message_rejected' => [E_WARNING, 'Undefined array key 0 in unrelated state', '/var/www/crmeb/services/image/gif/Encoder.php', 77, false],
    ];

    $cases = [];
    $passed = true;
    foreach ($fixtures as $name => $fixture) {
        [$severity, $message, $file, $line, $expected] = $fixture;
        $actual = isKnownStaticGifWarning($severity, $message, $file, $line);
        $casePassed = $actual === $expected;
        $cases[$name] = [
            'passed' => $casePassed,
            'expected_allowlist_result' => $expected,
            'actual_allowlist_result' => $actual,
        ];
        $passed = $passed && $casePassed;
    }

    return [
        'status' => $passed ? 'pass' : 'fail',
        'cases' => $cases,
    ];
}

function runProbeSelfTest(): array
{
    $tolerance = runToleranceSelfTest();
    $diagnosticAllowlist = runDiagnosticAllowlistSelfTest();
    return [
        'status' => $tolerance['status'] === 'pass' && $diagnosticAllowlist['status'] === 'pass' ? 'pass' : 'fail',
        'tolerance' => $tolerance,
        'diagnostic_allowlist' => $diagnosticAllowlist,
    ];
}

function compareOutputs(string $baselineDirectory, string $candidateDirectory): array
{
    $textPngCases = ['text_png_1', 'text_png_5', 'text_png_9'];
    $cases = [];
    $failures = [];
    foreach (outputFiles() as $name => $filename) {
        $baselinePath = $baselineDirectory . '/' . $filename;
        $candidatePath = $candidateDirectory . '/' . $filename;
        if (in_array($name, $textPngCases, true)) {
            $comparison = compareTextPngOutput($baselinePath, $candidatePath);
        } else {
            $expectedFrames = strpos($name, 'animated_gif_') === 0 ? 3 : null;
            $comparison = compareExactOutput($baselinePath, $candidatePath, $expectedFrames);
        }
        $cases[$name] = $comparison;
        if (!$comparison['passed']) {
            $failures[] = $name;
        }
    }

    $selfTest = runProbeSelfTest();
    if ($selfTest['status'] !== 'pass') {
        $failures[] = 'probe_self_test';
    }

    return [
        'status' => $failures === [] ? 'pass' : 'fail',
        'comparison' => 'php74_baseline_vs_php82_candidate',
        'policy' => [
            'non_text_png' => 'exact bytes and SHA256, with identical dimensions',
            'text_png_1_5_9' => 'identical dimensions, at most 1 differing pixel, identical alpha, maximum RGB channel delta 1',
            'animated_gif_frames' => 3,
        ],
        'self_test' => $selfTest,
        'cases' => $cases,
        'failures' => $failures,
    ];
}

function runCases(string $fixtureDirectory, string $outputDirectory): array
{
    ensureDirectory($outputDirectory);
    $font = '/var/www/vendor/topthink/think-image/tests/images/test.ttf';
    if (!is_file($font)) {
        fail('Think Image test font is missing');
    }

    $cases = [];
    foreach (['png' => 'source.png', 'static_gif' => 'source.gif', 'animated_gif' => 'animated.gif'] as $name => $source) {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $thumb = $outputDirectory . '/' . $name . '_thumb.' . $extension;
        Image::open($fixtureDirectory . '/' . $source)
            ->thumb(60, 40, Image::THUMB_FIXED)
            ->save($thumb);
        $cases[$name . '_thumb'] = summarize($thumb);

        $water = $outputDirectory . '/' . $name . '_water.' . $extension;
        Image::open($fixtureDirectory . '/' . $source)
            ->water($fixtureDirectory . '/watermark.png', Image::WATER_CENTER, 50)
            ->save($water);
        $cases[$name . '_water'] = summarize($water);
    }

    foreach (['png' => 'source.png', 'gif' => 'source.gif'] as $type => $source) {
        foreach ([Image::WATER_NORTHWEST, Image::WATER_CENTER, Image::WATER_SOUTHEAST] as $position) {
            $target = $outputDirectory . '/text_' . $type . '_' . $position . '.' . $type;
            Image::open($fixtureDirectory . '/' . $source)
                ->text('Store 2026', $font, 12, '#20304000', $position, [-3, 2], 15)
                ->save($target);
            $cases['text_' . $type . '_' . $position] = summarize($target);
        }
    }

    return $cases;
}

error_reporting(E_ALL);
$unexpectedDiagnostics = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$unexpectedDiagnostics): bool {
    if (isKnownStaticGifWarning($severity, $message, $file, $line)) {
        return true;
    }
    $unexpectedDiagnostics[] = [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ];
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$mode = $argv[1] ?? '';
$fixtureDirectory = $argv[2] ?? '';
try {
    require '/var/www/vendor/autoload.php';

    if ($mode === 'generate') {
        createFixtures($fixtureDirectory);
        echo json_encode([
            'status' => 'pass',
            'generated' => true,
            'unexpected_diagnostics' => $unexpectedDiagnostics,
        ], JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if ($mode === 'run') {
        $outputDirectory = $argv[3] ?? '';
        echo json_encode([
            'status' => 'pass',
            'unexpected_diagnostics' => $unexpectedDiagnostics,
            'cases' => runCases($fixtureDirectory, $outputDirectory),
        ], JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if ($mode === 'compare') {
        $candidateDirectory = $argv[3] ?? '';
        $result = compareOutputs($fixtureDirectory, $candidateDirectory);
        $result['unexpected_diagnostics'] = $unexpectedDiagnostics;
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
        exit($result['status'] === 'pass' ? 0 : 1);
    }
    if ($mode === 'self-test') {
        $result = runProbeSelfTest();
        $result['unexpected_diagnostics'] = $unexpectedDiagnostics;
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
        exit($result['status'] === 'pass' ? 0 : 1);
    }
    fail('Usage: php82-image-compatibility.php generate <fixture-dir> | run <fixture-dir> <output-dir> | compare <baseline-dir> <candidate-dir> | self-test');
} catch (Throwable $error) {
    echo json_encode([
        'status' => 'fail',
        'mode' => $mode,
        'unexpected_diagnostics' => $unexpectedDiagnostics,
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(1);
}
