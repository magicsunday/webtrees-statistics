<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Traits;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Mime;
use Fisharebest\Webtrees\Validator;
use MagicSunday\Webtrees\ModuleBase\Module\VersionInformation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function e;
use function file_exists;
use function file_get_contents;
use function is_file;
use function pathinfo;
use function response;
use function str_contains;
use function strtoupper;

use const PATHINFO_EXTENSION;

/**
 * Supplies ModuleCustomInterface metadata (author, version, support URL,
 * latest-version check) and loads compiled MO translation files for the
 * module's supported languages. Also overrides the core asset handler so
 * web fonts are served with their proper MIME type — without this Firefox
 * rejects WOFF2 assets returned as `application/octet-stream` (the core
 * MIME map has no WOFF / WOFF2 entry).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
trait ModuleCustomTrait
{
    use \Fisharebest\Webtrees\Module\ModuleCustomTrait;

    /**
     * Returns the module author's name.
     */
    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    /**
     * Returns the currently installed module version string.
     */
    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    /**
     * Returns the GitHub API URL used to check for newer releases.
     */
    public function customModuleLatestVersionUrl(): string
    {
        return self::CUSTOM_LATEST_VERSION;
    }

    /**
     * Fetches the latest published release version from GitHub, with a 24-hour file cache.
     * Falls back to the installed version when the API is unreachable.
     */
    public function customModuleLatestVersion(): string
    {
        return (new VersionInformation($this))->fetchLatestVersion();
    }

    /**
     * Returns the URL admins follow from the "An upgrade is available" notice
     * on the control panel and module-admin pages. Pointed at the GitHub
     * `/releases/latest` page so admins land directly on the release notes
     * and install-ready asset zip, rather than the bare issue tracker.
     */
    public function customModuleSupportUrl(): string
    {
        return self::CUSTOM_SUPPORT_URL;
    }

    /**
     * Loads translations from a compiled MO file for the requested language.
     * Returns an empty array when no translation file ships for the locale,
     * which lets webtrees core fall back to the English msgid baseline.
     *
     * @return array<string, string>
     */
    public function customTranslations(string $language): array
    {
        $catalogue = $this->resourcesFolder() . 'lang/' . $language . '/messages.mo';

        if (!is_file($catalogue)) {
            return [];
        }

        /** @var array<string, string> $translations */
        $translations = (new Translation($catalogue))->asArray();

        return $translations;
    }

    /**
     * Serves a static asset from the module's `resources/` directory with the
     * proper Content-Type header. Overrides the core implementation to add
     * MIME-type entries for web fonts (WOFF / WOFF2), which the core map
     * ({@see Mime::TYPES}) does not list. Without the override Firefox
     * rejects font downloads served as `application/octet-stream` with
     * `NS_ERROR_CORRUPTED_CONTENT`.
     *
     * @throws HttpAccessDeniedException When the requested path tries to escape the resources folder
     * @throws HttpNotFoundException     When the requested asset does not exist on disk
     */
    public function getAssetAction(ServerRequestInterface $request): ResponseInterface
    {
        $asset = Validator::queryParams($request)->string('asset');

        // Reject parent-folder traversal attempts (`..`) before resolving the path.
        if (str_contains($asset, '..')) {
            throw new HttpAccessDeniedException($asset);
        }

        $file = $this->resourcesFolder() . $asset;

        if (!file_exists($file)) {
            throw new HttpNotFoundException(e($file));
        }

        $content = file_get_contents($file);

        // Treat a TOCTOU read failure (file vanished or permissions stripped
        // between the file_exists() check above and the read here) as a
        // missing asset. An assertion would be silently stripped by
        // zend.assertions=-1 in production and `response(false, …)` then
        // raises an unhandled TypeError because response() requires
        // array|object|string under strict_types.
        if ($content === false) {
            throw new HttpNotFoundException(e($file));
        }

        $extension = strtoupper(pathinfo($asset, PATHINFO_EXTENSION));
        $mimeType  = static::ASSET_MIME_TYPES[$extension] ?? Mime::TYPES[$extension] ?? Mime::DEFAULT_TYPE;

        return response($content, StatusCodeInterface::STATUS_OK, [
            'cache-control' => 'public,max-age=31536000',
            'content-type'  => $mimeType,
        ]);
    }
}
