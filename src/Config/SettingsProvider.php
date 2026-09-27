<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Config;

use Qoliber\Trident\Config\Settings;
use Qoliber\Trident\Config\SettingsResolver;
use Qoliber\Trident\Delivery\Instances;
use Qoliber\Trident\Security\TokenVault;

/**
 * The resolved Trident settings for this request (the shared
 * {@see SettingsResolver} rules): the DEPLOYMENT configuration wins —
 * `TRIDENT_*` environment variables, or `trident_*` keys in
 * `app/config/parameters.php` — and the back office's single URL and token
 * are the fallback for a one-server shop.
 *
 * The back-office token is stored SEALED to its URL ({@see TokenVault}, key
 * derived from the shop's `_COOKIE_KEY_`): changing the URL makes it
 * unusable until it is entered again, so whoever can edit the settings cannot
 * send the token to their own host. A token from the environment goes only to
 * URLs from the environment.
 */
final class SettingsProvider
{
    public const TOKEN_INFO = 'qoliber/trident-cache-prestashop:api-token:v1';
    public const KEY_URL = 'TRIDENT_API_URL';
    public const KEY_TOKEN = 'TRIDENT_API_TOKEN';
    public const KEY_MODE = 'TRIDENT_PURGE_MODE';
    public const ENV = [
        'TRIDENT_INSTANCES', 'TRIDENT_API_URL', 'TRIDENT_API_TOKEN', 'TRIDENT_PURGE_MODE',
        'TRIDENT_TAG_PREFIX', 'TRIDENT_ALLOWED_API_HOSTS', 'TRIDENT_DEBUG_HEADERS',
    ];

    private static ?Settings $settings = null;
    private static ?string $tokenError = null;

    public static function get(): Settings
    {
        return self::$settings ??= self::resolve();
    }

    /** Settings changed in this process (the back office saved them). */
    public static function reset(): void
    {
        self::$settings = null;
        self::$tokenError = null;
    }

    public static function vault(): TokenVault
    {
        return new TokenVault((string) (\defined('_COOKIE_KEY_') ? _COOKIE_KEY_ : ''), self::TOKEN_INFO);
    }

    /**
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $env = [];
        $params = self::parameters();
        foreach (self::ENV as $name) {
            $value = getenv($name);
            if (!\is_string($value) || $value === '') {
                $value = isset($_SERVER[$name]) && \is_scalar($_SERVER[$name]) ? (string) $_SERVER[$name] : '';
            }
            if ($value === '') {
                $key = strtolower($name);
                $fromParams = $params[$key] ?? null;
                if (\is_array($fromParams)) {
                    $value = (string) json_encode($fromParams);
                } elseif (\is_scalar($fromParams)) {
                    $value = (string) $fromParams;
                }
            }
            if ($value !== '') {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * The back-office values, the token opened for the stored URL.
     *
     * @return array<string, mixed>
     */
    public static function stored(): array
    {
        try {
            $url = (string) \Configuration::getGlobalValue(self::KEY_URL);
            $sealed = (string) \Configuration::getGlobalValue(self::KEY_TOKEN);
            $mode = (string) \Configuration::getGlobalValue(self::KEY_MODE);
        } catch (\Throwable) {
            return ['read_failed' => true];
        }
        $token = null;
        $error = null;
        if ($sealed !== '') {
            $token = self::vault()->open($sealed, $url);
            if ($token === null) {
                $error = 'The stored Trident token does not open for this URL (the URL or the shop secret changed): re-enter the token';
            }
        }
        self::$tokenError = $error;

        return ['api_url' => $url, 'api_token' => $token, 'token_error' => $error, 'purge_mode' => $mode];
    }

    /**
     * Save the back-office settings. An empty token keeps the stored one only
     * when the URL is unchanged (it is sealed to that URL).
     *
     * @return string|null an error, or null when saved
     */
    public static function save(string $url, string $token, string $mode): ?string
    {
        $url = trim($url);
        if ($url !== '' && !Instances::isHttpUrl($url)) {
            return 'The API URL must be http(s)://host[:port][/path] — no query, fragment or credentials';
        }
        $previousUrl = (string) \Configuration::getGlobalValue(self::KEY_URL);
        $sealed = (string) \Configuration::getGlobalValue(self::KEY_TOKEN);
        if ($token !== '') {
            $sealed = self::vault()->seal($token, $url);
        } elseif ($url !== $previousUrl) {
            $sealed = '';
        }
        \Configuration::updateGlobalValue(self::KEY_URL, $url);
        \Configuration::updateGlobalValue(self::KEY_TOKEN, $sealed);
        \Configuration::updateGlobalValue(self::KEY_MODE, $mode === Settings::MODE_HARD ? Settings::MODE_HARD : Settings::MODE_SOFT);
        self::reset();

        return null;
    }

    /**
     * What the back office shows — never a token.
     *
     * @return array<string, mixed>
     */
    public static function view(): array
    {
        $s = self::get();

        return [
            'source' => $s->source,
            'mode' => $s->mode,
            'instances' => array_map(static fn ($i): array => ['name' => $i->name, 'api_url' => $i->apiUrl, 'has_token' => $i->apiToken !== ''], $s->instances),
            'errors' => $s->errors,
            'tag_prefix' => $s->tagPrefix,
            'api_url' => (string) \Configuration::getGlobalValue(self::KEY_URL),
            'has_token' => (string) \Configuration::getGlobalValue(self::KEY_TOKEN) !== '',
            'token_error' => self::$tokenError,
        ];
    }

    private static function resolve(): Settings
    {
        return SettingsResolver::resolve(self::environment(), self::stored());
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameters(): array
    {
        $file = \defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ . '/app/config/parameters.php' : '';
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $config = include $file;

        return \is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
    }
}
