<?php

/**
 * @package     Pnkr.Plugin
 * @subpackage  Content.Pnkrindexnow
 *
 * @copyright   Copyright (C) 2025 Panagiotis Kiriakopoulos. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Pnkr\Plugin\Content\Pnkrindexnow\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Event\Model\AfterChangeStateEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Http\HttpFactory;
use Joomla\Registry\Registry;

/**
 * IndexNow Content Plugin
 *
 * @since  1.0.0
 */
final class Pnkrindexnow extends CMSPlugin implements SubscriberInterface
{
    /**
     * Allowed IndexNow endpoints. Never build the request host from unchecked input.
     *
     * @var    string[]
     * @since  1.2.0
     */
    private const ALLOWED_ENDPOINTS = [
        'api.indexnow.org',
        'www.bing.com',
        'search.yahoo.com',
        'yandex.com',
    ];

    /**
     * Key format allowed by the IndexNow specification.
     *
     * @var    string
     * @since  1.2.0
     */
    private const KEY_PATTERN = '/^[a-zA-Z0-9-]{8,128}$/';

    /**
     * Log category used by this plugin.
     *
     * @var    string
     * @since  1.2.0
     */
    private const LOG_CATEGORY = 'plg_content_pnkrindexnow';

    /**
     * HTTP timeout in seconds for the IndexNow request.
     *
     * @var    int
     * @since  1.2.0
     */
    private const HTTP_TIMEOUT = 10;

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Whether the log file logger has been registered.
     *
     * @var    boolean
     * @since  1.2.0
     */
    private bool $loggerRegistered = false;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterSave'   => 'onContentAfterSave',
            'onContentChangeState' => 'onContentChangeState',
            'onContentAfterDelete' => 'onContentAfterDelete',
            'onExtensionAfterSave' => 'onExtensionAfterSave',
        ];
    }

    /**
     * Notifies IndexNow after an article is saved
     *
     * @param   AfterSaveEvent  $event  The event object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $article = $event->getItem();

        if ((bool) $this->params->get('notify_on_publish_only', 1) && !$this->isLive($article)) {
            $this->logDebug('Article is not published or outside its publishing window, skipping IndexNow notification');
            return;
        }

        if (!$this->isPublicAccess($article)) {
            $this->logDebug('Article is not publicly accessible, skipping IndexNow notification');
            return;
        }

        if ($this->isNoindexArticle($article)) {
            $this->logDebug('Article is marked with noindex, skipping IndexNow notification');
            return;
        }

        $url = $this->getArticleUrl($article);

        if ($url === '') {
            $this->logDebug('Could not generate article URL');
            return;
        }

        $this->notifyIndexNow([$url]);
    }

    /**
     * Notifies IndexNow when article state changes
     *
     * @param   AfterChangeStateEvent  $event  The event object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentChangeState(AfterChangeStateEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $value     = $event->getValue();
        $isPublish = $value === 1;
        $isRemoval = (bool) $this->params->get('notify_on_remove', 1) && \in_array($value, [0, -2], true);

        if (!$isPublish && !$isRemoval) {
            return;
        }

        try {
            $table = $this->getApplication()->bootComponent('com_content')
                ->getMVCFactory()
                ->createTable('Article', 'Administrator');
        } catch (\Throwable $e) {
            $this->log('Could not load the article table: ' . $e->getMessage(), Log::ERROR);
            return;
        }

        $urls = [];

        foreach ($event->getPks() as $pk) {
            $table->reset();
            $table->id = null;

            if (!$table->load((int) $pk)) {
                continue;
            }

            // Never disclose URLs of articles a guest cannot see
            if (!$this->isPublicAccess($table)) {
                continue;
            }

            if ($isPublish && (!$this->isLive($table) || $this->isNoindexArticle($table))) {
                continue;
            }

            if ($isRemoval && (int) $table->state === 1) {
                continue;
            }

            $url = $this->getArticleUrl($table);

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $this->notifyIndexNow($urls, $isRemoval);
    }

    /**
     * Notifies IndexNow when an article is deleted
     *
     * @param   AfterDeleteEvent  $event  The event object
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function onContentAfterDelete(AfterDeleteEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        if (!(bool) $this->params->get('notify_on_remove', 1)) {
            return;
        }

        $article = $event->getItem();

        if (empty($article->id) || !$this->isPublicAccess($article)) {
            return;
        }

        $url = $this->getArticleUrl($article);

        if ($url !== '') {
            $this->notifyIndexNow([$url], true);
        }
    }

    /**
     * Creates the key file when the plugin settings are saved and removes the file of a replaced key
     *
     * @param   AfterSaveEvent  $event  The event object
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function onExtensionAfterSave(AfterSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_plugins.plugin') {
            return;
        }

        $extension = $event->getItem();

        if (($extension->element ?? '') !== 'pnkrindexnow' || ($extension->folder ?? '') !== 'content') {
            return;
        }

        $newKey = (string) (new Registry($extension->params ?? ''))->get('api_key', '');

        // $this->params still holds the settings loaded before this save
        $oldKey = (string) $this->params->get('api_key', '');

        if ($oldKey !== '' && $oldKey !== $newKey) {
            $this->removeKeyFile($oldKey);
        }

        if ($newKey !== '' && !$this->ensureKeyFile($newKey)) {
            $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_KEYFILE_CREATE'), 'warning');
        }
    }

    /**
     * Check that the article is published and inside its publishing window
     *
     * @param   object  $article  The article object
     *
     * @return  bool
     *
     * @since   1.2.0
     */
    private function isLive(object $article): bool
    {
        if ((int) ($article->state ?? 0) !== 1) {
            return false;
        }

        $now = Factory::getDate()->toUnix();

        if (!empty($article->publish_up) && Factory::getDate($article->publish_up)->toUnix() > $now) {
            return false;
        }

        if (!empty($article->publish_down) && Factory::getDate($article->publish_down)->toUnix() <= $now) {
            return false;
        }

        return true;
    }

    /**
     * Check that a guest can view the article and its category
     *
     * @param   object  $article  The article object
     *
     * @return  bool
     *
     * @since   1.2.0
     */
    private function isPublicAccess(object $article): bool
    {
        $guestLevels = array_map('intval', Access::getAuthorisedViewLevels(0));

        if (!\in_array((int) ($article->access ?? 0), $guestLevels, true)) {
            return false;
        }

        $category = $this->getCategory((int) ($article->catid ?? 0));

        // Categories only returns published categories
        return $category !== null && \in_array((int) $category->access, $guestLevels, true);
    }

    /**
     * Load a published content category
     *
     * @param   int  $catid  The category id
     *
     * @return  object|null
     *
     * @since   1.2.0
     */
    private function getCategory(int $catid): ?object
    {
        if ($catid <= 0) {
            return null;
        }

        try {
            return $this->getApplication()->bootComponent('com_content')
                ->getCategory(['access' => false])
                ->get($catid);
        } catch (\Throwable $e) {
            $this->log('Could not load category ' . $catid . ': ' . $e->getMessage(), Log::WARNING);

            return null;
        }
    }

    /**
     * Get the absolute site URL for an article
     *
     * @param   object  $article  The article object
     *
     * @return  string  The article URL, or an empty string on failure
     *
     * @since   1.0.0
     */
    private function getArticleUrl(object $article): string
    {
        if (empty($article->id)) {
            return '';
        }

        $link = 'index.php?option=com_content&view=article'
            . '&id=' . (int) $article->id . ':' . ($article->alias ?? '')
            . '&catid=' . (int) ($article->catid ?? 0);

        $language = $article->language ?? '*';

        if ($language !== '*' && $language !== '' && Multilanguage::isEnabled()) {
            $link .= '&lang=' . $language;
        }

        $tls = (int) $this->getApplication()->get('force_ssl', 0) === 2 ? Route::TLS_FORCE : Route::TLS_IGNORE;

        try {
            return Route::link('site', $link, false, $tls, true);
        } catch (\Throwable $e) {
            $this->log('Could not route article ' . (int) $article->id . ': ' . $e->getMessage(), Log::WARNING);

            return '';
        }
    }

    /**
     * Check if an article is marked with noindex, at article, category or global level
     *
     * @param   object  $article  The article object
     *
     * @return  bool  True if the article should not be indexed
     *
     * @since   1.2.0
     */
    private function isNoindexArticle(object $article): bool
    {
        $robots = $this->getRobots($article->metadata ?? null);

        if ($robots === '') {
            $category = $this->getCategory((int) ($article->catid ?? 0));
            $robots   = $category ? $this->getRobots($category->metadata ?? null) : '';
        }

        if ($robots === '') {
            $robots = (string) $this->getApplication()->get('robots', '');
        }

        // It could be "noindex, follow" or "noindex, nofollow"
        return stripos($robots, 'noindex') !== false;
    }

    /**
     * Read the robots value from a metadata JSON string or Registry
     *
     * @param   mixed  $metadata  The metadata
     *
     * @return  string
     *
     * @since   1.2.0
     */
    private function getRobots($metadata): string
    {
        if (empty($metadata)) {
            return '';
        }

        if (!$metadata instanceof Registry) {
            if (!\is_string($metadata)) {
                return '';
            }

            try {
                $metadata = new Registry($metadata);
            } catch (\RuntimeException $e) {
                return '';
            }
        }

        return (string) $metadata->get('robots', '');
    }

    /**
     * Send the URLs to the IndexNow API in a single request
     *
     * @param   string[]  $urls       The URLs to notify
     * @param   bool      $isRemoval  Whether the URLs were removed (unpublished, trashed or deleted)
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function notifyIndexNow(array $urls, bool $isRemoval = false): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if (!$urls) {
            return;
        }

        $apiKey = (string) $this->params->get('api_key', '');

        if ($apiKey === '') {
            $this->logDebug('IndexNow API key is not configured');
            return;
        }

        if (!$this->ensureKeyFile($apiKey)) {
            $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_KEYFILE_CREATE'), 'warning');
            return;
        }

        $searchEngine = (string) $this->params->get('search_engine', 'api.indexnow.org');

        if (!\in_array($searchEngine, self::ALLOWED_ENDPOINTS, true)) {
            $this->log('Unknown search engine endpoint, falling back to api.indexnow.org', Log::WARNING);
            $searchEngine = 'api.indexnow.org';
        }

        $apiUrl = 'https://' . $searchEngine . '/indexnow';
        $uri    = new Uri($urls[0]);

        // IndexNow allows up to 10,000 URLs per request
        $data = [
            'host'        => $uri->getHost(),
            'key'         => $apiKey,
            'keyLocation' => $uri->toString(['scheme', 'host', 'port']) . '/' . $apiKey . '.txt',
            'urlList'     => \array_slice($urls, 0, 10000),
        ];

        $displayUrl = \count($urls) === 1 ? $urls[0] : implode(', ', $urls);

        try {
            $http = (new HttpFactory())->getHttp([
                'userAgent' => (new Version())->getUserAgent('Joomla', true, false),
            ]);

            $response = $http->post(
                $apiUrl,
                json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json; charset=utf-8'],
                self::HTTP_TIMEOUT
            );
        } catch (\Throwable $e) {
            $this->addMessage(Text::sprintf('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_EXCEPTION', $this->escape($e->getMessage())), 'error');
            $this->log('IndexNow notification exception: ' . $e->getMessage(), Log::ERROR);

            return;
        }

        $statusCode = $response->getStatusCode();
        $body       = (string) $response->getBody();

        // Handle response according to IndexNow specification
        switch ($statusCode) {
            case 200:
                $this->addMessage(
                    Text::sprintf(
                        $isRemoval ? 'PLG_CONTENT_PNKRINDEXNOW_MSG_REMOVED' : 'PLG_CONTENT_PNKRINDEXNOW_MSG_SUCCESS',
                        $this->escape($displayUrl)
                    ),
                    'success'
                );
                $this->logDebug('Successfully notified IndexNow for: ' . $displayUrl);
                break;

            case 202:
                $this->addMessage(
                    Text::sprintf(
                        $isRemoval ? 'PLG_CONTENT_PNKRINDEXNOW_MSG_REMOVED_ACCEPTED' : 'PLG_CONTENT_PNKRINDEXNOW_MSG_ACCEPTED',
                        $this->escape($displayUrl)
                    ),
                    'success'
                );
                $this->logDebug('IndexNow accepted: ' . $displayUrl);
                break;

            case 400:
                $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_BAD_REQUEST'), 'error');
                $this->log('Bad request (400): ' . $body, Log::ERROR);
                break;

            case 403:
                $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_FORBIDDEN'), 'error');
                $this->log('Forbidden (403): Key not found or invalid. Response: ' . $body, Log::ERROR);
                break;

            case 422:
                $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_UNPROCESSABLE'), 'error');
                $this->log('Unprocessable Entity (422): ' . $body, Log::ERROR);
                break;

            case 429:
                $this->addMessage(Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_TOO_MANY'), 'warning');
                $this->log('Too Many Requests (429): Rate limit exceeded', Log::WARNING);
                break;

            default:
                $this->addMessage(Text::sprintf('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_UNKNOWN', $statusCode), 'warning');
                $this->log(sprintf('Unexpected response code %d: %s', $statusCode, $body), Log::WARNING);
                break;
        }
    }

    /**
     * Show a message to backend users only. Frontend, API and CLI requests get no messages.
     *
     * @param   string  $message  The message, already escaped
     * @param   string  $type     The message type
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function addMessage(string $message, string $type = 'message'): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            $app->enqueueMessage($message, $type);
        }
    }

    /**
     * Escape a value for HTML output
     *
     * @param   string  $value  The value
     *
     * @return  string
     *
     * @since   1.2.0
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Write a message to the plugin log file
     *
     * @param   string  $message   The message
     * @param   int     $priority  The Log priority
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function log(string $message, int $priority = Log::INFO): void
    {
        if (!$this->loggerRegistered) {
            Log::addLogger(
                ['text_file' => self::LOG_CATEGORY . '.php'],
                $this->params->get('debug_mode', 0) ? Log::ALL : (Log::EMERGENCY | Log::ALERT | Log::CRITICAL | Log::ERROR | Log::WARNING),
                [self::LOG_CATEGORY]
            );

            $this->loggerRegistered = true;
        }

        Log::add($message, $priority, self::LOG_CATEGORY);
    }

    /**
     * Log debug messages and show them to backend users if debug mode is enabled
     *
     * @param   string  $message  The message to log
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function logDebug(string $message): void
    {
        if (!$this->params->get('debug_mode', 0)) {
            return;
        }

        $this->log($message, Log::DEBUG);
        $this->addMessage('[IndexNow Plugin] ' . $this->escape($message), 'info');
    }

    /**
     * Get the key file path for a key, or null when the key is not valid
     *
     * @param   string  $apiKey  The API key
     *
     * @return  string|null
     *
     * @since   1.2.0
     */
    private function getKeyFilePath(string $apiKey): ?string
    {
        // The pattern only allows [a-zA-Z0-9-], so the key cannot contain path separators
        if (!preg_match(self::KEY_PATTERN, $apiKey)) {
            return null;
        }

        return JPATH_ROOT . '/' . $apiKey . '.txt';
    }

    /**
     * Ensure the IndexNow key file exists in the site root and contains the key
     *
     * @param   string  $apiKey  The API key
     *
     * @return  bool  True if the key file exists or was created successfully
     *
     * @since   1.0.0
     */
    private function ensureKeyFile(string $apiKey): bool
    {
        $keyFile = $this->getKeyFilePath($apiKey);

        if ($keyFile === null) {
            $this->log('Invalid API key format. Only 8-128 letters, digits and dashes are allowed.', Log::WARNING);

            return false;
        }

        if (is_file($keyFile)) {
            if (trim((string) file_get_contents($keyFile)) === $apiKey) {
                return true;
            }

            // Never overwrite a file we did not create
            $this->log('A file named ' . basename($keyFile) . ' already exists with different content', Log::ERROR);

            return false;
        }

        try {
            return File::write($keyFile, $apiKey);
        } catch (\Throwable $e) {
            $this->log('Could not create key file: ' . $e->getMessage(), Log::ERROR);

            return false;
        }
    }

    /**
     * Remove the key file of a replaced key, only if it was created by this plugin
     *
     * @param   string  $apiKey  The old API key
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function removeKeyFile(string $apiKey): void
    {
        $keyFile = $this->getKeyFilePath($apiKey);

        if ($keyFile === null || !is_file($keyFile) || trim((string) file_get_contents($keyFile)) !== $apiKey) {
            return;
        }

        try {
            File::delete($keyFile);
        } catch (\Throwable $e) {
            $this->log('Could not delete old key file: ' . $e->getMessage(), Log::WARNING);
        }
    }
}
