<?php
/**
 * Trident Cache in the back office (Advanced Parameters → Trident Cache):
 * the shared admin screens (dashboard, purge, cached pages, tags, coverage,
 * warmer, launch, reflect, denoisers, bans, backends, discovery, events) and
 * the settings.
 *
 * - Reading needs the tab's "view" permission, acting needs "edit".
 * - Every action is a POST carrying a CSRF token (an HMAC of the employee's
 *   session, keyed with the shop secret; compared with hash_equals); actions that change what every visitor is served need an
 *   explicit confirmation, checked on the server by the shared AdminService.
 * - `Accept: application/json` gets the data as JSON (automation, the e2e).
 *
 * @author    qoliber
 * @license   MIT
 */

declare(strict_types=1);

use Qoliber\Trident\Admin\AdminException;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\TridentPrestaShop\Admin\PsAdminContext;
use Qoliber\TridentPrestaShop\Config\SettingsProvider;
use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;

class AdminTridentCacheController extends ModuleAdminController
{
    private const TOKEN_INTENT = 'tridentcache_actions';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent(): void
    {
        if (!$this->access('view')) {
            $this->respond(['error' => 'no permission'], 403);

            return;
        }
        $screen = (string) Tools::getValue('screen', 'dashboard');
        if (Tools::isSubmit('trident_action') || Tools::isSubmit('trident_settings')) {
            $this->post();

            return;
        }
        try {
            // The cron token is a credential: shown (and generated) for edit
            // permission only.
            $data = $screen === 'settings'
                ? SettingsProvider::view() + ($this->access('edit') ? ['cron_url' => $this->cronUrl(), 'cron_header' => 'X-Trident-Cron-Token'] : [])
                : PsAdminContext::service()->screen($screen, $this->queryArgs());
            $this->respond(['screen' => $screen, 'data' => $data, 'token' => $this->formToken(), 'confirm' => AdminService::CONFIRM]);
        } catch (AdminException $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->respond(['error' => 'Trident: ' . $e->getMessage()], 500);
        }
    }

    private function post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(['error' => 'POST only'], 405);

            return;
        }
        if (!$this->access('edit')) {
            $this->respond(['error' => 'no permission'], 403);

            return;
        }
        if (!$this->validToken((string) Tools::getValue('trident_token'))) {
            $this->respond(['error' => 'invalid form token'], 403);

            return;
        }
        if (Tools::isSubmit('trident_settings')) {
            $error = SettingsProvider::save(
                (string) Tools::getValue('api_url'),
                (string) Tools::getValue('api_token'),
                (string) Tools::getValue('purge_mode')
            );
            DeliveryFactory::reset();
            $this->respond($error === null ? ['saved' => true, 'data' => SettingsProvider::view()] : ['error' => $error], $error === null ? 200 : 400);

            return;
        }
        $body = $_POST;
        unset($body['trident_token'], $body['trident_action']);
        $body['confirm'] = in_array($body['confirm'] ?? null, ['1', 'on', 'true', true], true);
        try {
            $result = PsAdminContext::service()->action((string) Tools::getValue('trident_action'), $body);
            DeliveryFactory::instance()->flushAfterResponse();
            $this->respond(['result' => $result]);
        } catch (AdminException $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->respond(['error' => 'Trident: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: private, no-store');
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            exit;
        }
        $this->context->smarty->assign([
            'trident' => $payload,
            'trident_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'trident_screens' => array_merge(AdminService::SCREENS, ['settings']),
            'trident_link' => $this->context->link->getAdminLink('AdminTridentCache'),
        ]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'tridentcache/views/templates/admin/screen.tpl');
        parent::initContent();
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || Tools::getValue('format') === 'json';
    }

    /**
     * The actions' CSRF token: an HMAC of this employee and their back-office
     * session, keyed with the shop's secret — stable for the session (so the
     * screen's token validates on the POST), useless in any other session,
     * and impossible to compute without `_COOKIE_KEY_`. (PrestaShop 9's own
     * admin tokens are randomised per call, and its CSRF manager is a private
     * service.)
     */
    private function formToken(): string
    {
        $cookie = $this->context->cookie;
        $session = (string) ($cookie->session_token ?? '') . '|' . (string) ($cookie->session_id ?? '');

        return hash_hmac('sha256', self::TOKEN_INTENT . '|' . (int) $this->context->employee->id . '|' . $session, _COOKIE_KEY_);
    }

    private function validToken(string $given): bool
    {
        return $given !== '' && hash_equals($this->formToken(), $given);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryArgs(): array
    {
        $args = $_GET;
        foreach (['controller', 'token', 'screen', 'format', '_token'] as $k) {
            unset($args[$k]);
        }

        return $args;
    }

    private function cronUrl(): string
    {
        $token = (string) Configuration::getGlobalValue('TRIDENT_CRON_TOKEN');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            Configuration::updateGlobalValue('TRIDENT_CRON_TOKEN', $token);
        }

        return $this->context->link->getModuleLink('tridentcache', 'cron', ['token' => $token]);
    }
}
