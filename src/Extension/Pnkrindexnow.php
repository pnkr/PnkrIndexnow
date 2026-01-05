<?php

/**
 * @package     Pnkr.Plugin
 * @subpackage  Content.Pnkrindexnow
 *
 * @copyright   Copyright (C) 2025 Pnkr. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Pnkr\Plugin\Content\Pnkrindexnow\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * IndexNow Content Plugin
 *
 * @since  1.0.0
 */
final class Pnkrindexnow extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

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
            'onContentAfterSave' => 'onContentAfterSave',
            'onContentChangeState' => 'onContentChangeState',
            'onContentAfterDelete' => 'onContentAfterDelete',
        ];
    }

    /**
     * Plugin that notifies IndexNow after an article is saved
     *
     * @param   Event  $event  The event object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentAfterSave(Event $event): void
    {
        [$context, $article, $isNew] = array_values($event->getArguments());

        // Only handle articles
        if ($context !== 'com_content.article') {
            return;
        }

        // Check if we should only notify on published articles
        $notifyPublishOnly = $this->params->get('notify_on_publish_only', 1);
        
        if ($notifyPublishOnly && $article->state != 1) {
            $this->logDebug('Article is not published, skipping IndexNow notification');
            return;
        }

        // Check if article is marked with noindex
        if ($this->isNoindexArticle($article)) {
            $this->logDebug('Article is marked with noindex, skipping IndexNow notification');
            return;
        }

        // Generate the article URL
        $url = $this->getArticleUrl($article);

        if (empty($url)) {
            $this->logDebug('Could not generate article URL');
            return;
        }

        // Send notification to IndexNow
        $this->notifyIndexNow($url);
    }

    /**
     * Plugin that notifies IndexNow when article state changes
     *
     * @param   Event  $event  The event object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentChangeState(Event $event): void
    {
        [$context, $pks, $value] = array_values($event->getArguments());

        // Only handle articles
        if ($context !== 'com_content.article') {
            return;
        }

        $notifyRemovals = (bool) $this->params->get('notify_on_remove', 1);
        $isPublish = ((int) $value) === 1;
        $isRemoval = $notifyRemovals && in_array((int) $value, [0, -2], true);

        if (!$isPublish && !$isRemoval) {
            return;
        }

        // Load each article and notify
        $model = $this->getApplication()->bootComponent('com_content')
            ->getMVCFactory()
            ->createModel('Article', 'Site', ['ignore_request' => true]);

        foreach ($pks as $pk) {
            $article = $model->getItem($pk);

            if (!$article) {
                continue;
            }

            // Skip articles marked with noindex when publishing
            if ($isPublish && $this->isNoindexArticle($article)) {
                $this->logDebug('Article is marked with noindex, skipping IndexNow notification');
                continue;
            }

            $url = $this->getArticleUrl($article);

            if (empty($url)) {
                continue;
            }

            if ($isPublish && ((int) $article->state) === 1) {
                $this->notifyIndexNow($url);
                continue;
            }

            if ($isRemoval && ((int) $article->state) !== 1) {
                $this->notifyIndexNow($url, true);
            }
        }
    }

    /**
     * Plugin that notifies IndexNow when an article is deleted
     *
     * @param   Event  $event  The event object
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function onContentAfterDelete(Event $event): void
    {
        [$context, $table] = array_values($event->getArguments());

        if ($context !== 'com_content.article') {
            return;
        }

        if (!(bool) $this->params->get('notify_on_remove', 1)) {
            return;
        }

        if (empty($table->id)) {
            return;
        }

        // Build a lightweight article object to reuse the URL builder
        $article = (object) [
            'id' => $table->id,
            'alias' => $table->alias ?? '',
            'catid' => $table->catid ?? 0,
            'category_alias' => $table->category_alias ?? ''
        ];

        $url = $this->getArticleUrl($article);

        if (!empty($url)) {
            $this->notifyIndexNow($url, true);
        }
    }

    /**
     * Get the full URL for an article
     *
     * @param   object  $article  The article object
     *
     * @return  string  The article URL
     *
     * @since   1.0.0
     */
    private function getArticleUrl($article): string
    {
        if (empty($article->id)) {
            return '';
        }

        // Build the article link
        $slug = $article->id . ':' . $article->alias;
        $catslug = $article->catid . ':' . ($article->category_alias ?? '');
        
        // Use the router to build the absolute URL (5th parameter = true makes it absolute)
        $link = Route::link(
            'site',
            'index.php?option=com_content&view=article&id=' . $slug . '&catid=' . $catslug,
            false,
            Route::TLS_IGNORE,
            true
        );
        
        return $link;
    }

    /**
     * Check if an article is marked with noindex
     *
     * @param   object  $article  The article object
     *
     * @return  bool  True if the article should not be indexed
     *
     * @since   1.2.0
     */
    private function isNoindexArticle($article): bool
    {
        // In Joomla, metadata is stored as JSON string or Registry object
        if (empty($article->metadata)) {
            return false;
        }

        // Handle both string (JSON) and Registry object
        if (is_string($article->metadata)) {
            $metadata = json_decode($article->metadata, true);
            if (!is_array($metadata)) {
                return false;
            }
            $robots = $metadata['robots'] ?? '';
        } else {
            // It's a Registry object
            $robots = $article->metadata->get('robots');
        }

        // If no robots setting, not marked as noindex
        if (empty($robots)) {
            return false;
        }

        $robots = (string) $robots;
        
        // Check if noindex is in the robots meta value
        // It could be "noindex, follow" or "noindex, nofollow"
        return stripos($robots, 'noindex') !== false;
    }

    /**
     * Send notification to IndexNow API
     *
     * @param   string  $url  The URL to notify
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function notifyIndexNow(string $url, bool $isRemoval = false): void
    {
        $apiKey = $this->params->get('api_key', '');
        
        if (empty($apiKey)) {
            $this->logDebug('IndexNow API key is not configured');
            return;
        }

        // Ensure key file exists at domain root
        if (!$this->ensureKeyFile($apiKey)) {
            $this->logDebug('Could not create or verify key file');
            return;
        }

        $searchEngine = $this->params->get('search_engine', 'api.indexnow.org');
        
        // Build the IndexNow API endpoint
        $apiUrl = 'https://' . $searchEngine . '/indexnow';
        
        // Get the host from the URL
        $uri = Uri::getInstance($url);
        $host = $uri->getHost();
        
        // Build the key location URL
        $keyLocation = $uri->toString(['scheme', 'host', 'port']) . '/' . $apiKey . '.txt';

        // Prepare the data according to IndexNow specification
        $data = [
            'host' => $host,
            'key' => $apiKey,
            'keyLocation' => $keyLocation,  // Points to root
            'urlList' => [$url]
        ];

        try {
            // Get HTTP client
            $http = HttpFactory::getHttp();
            
            // Send POST request
            $response = $http->post(
                $apiUrl,
                json_encode($data),
                [
                    'Content-Type' => 'application/json; charset=utf-8'
                ]
            );

            $statusCode = $response->code;
            
            // Handle response according to IndexNow specification
            switch ($statusCode) {
                case 200:
                    // Success - URL submitted successfully
                    $this->getApplication()->enqueueMessage(
                        Text::sprintf(
                            $isRemoval ? 'PLG_CONTENT_PNKRINDEXNOW_MSG_REMOVED' : 'PLG_CONTENT_PNKRINDEXNOW_MSG_SUCCESS',
                            $url
                        ),
                        'success'
                    );
                    $this->logDebug(sprintf('Successfully notified IndexNow for URL: %s', $url));
                    break;

                case 202:
                    // Accepted - URL received and will be processed
                    $this->getApplication()->enqueueMessage(
                        Text::sprintf(
                            $isRemoval ? 'PLG_CONTENT_PNKRINDEXNOW_MSG_REMOVED_ACCEPTED' : 'PLG_CONTENT_PNKRINDEXNOW_MSG_ACCEPTED',
                            $url
                        ),
                        'success'
                    );
                    break;

                case 400:
                    // Bad request - Invalid format
                    $this->getApplication()->enqueueMessage(
                        Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_BAD_REQUEST'),
                        'error'
                    );
                    $this->logDebug(sprintf('Bad request (400): %s', $response->body));
                    break;

                case 403:
                    // Forbidden - Key not valid
                    $this->getApplication()->enqueueMessage(
                        Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_FORBIDDEN'),
                        'error'
                    );
                    $this->logDebug(sprintf('Forbidden (403): Key not found or invalid. Response: %s', $response->body));
                    break;

                case 422:
                    // Unprocessable Entity - URLs don't belong to host or key mismatch
                    $this->getApplication()->enqueueMessage(
                        Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_UNPROCESSABLE'),
                        'error'
                    );
                    $this->logDebug(sprintf('Unprocessable Entity (422): %s', $response->body));
                    break;

                case 429:
                    // Too Many Requests - Potential spam
                    $this->getApplication()->enqueueMessage(
                        Text::_('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_TOO_MANY'),
                        'warning'
                    );
                    $this->logDebug('Too Many Requests (429): Rate limit exceeded');
                    break;

                default:
                    // Unknown response
                    $this->getApplication()->enqueueMessage(
                        Text::sprintf('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_UNKNOWN', $statusCode),
                        'warning'
                    );
                    $this->logDebug(sprintf('Unexpected response code %d: %s', $statusCode, $response->body));
                    break;
            }
        } catch (\Exception $e) {
            $this->getApplication()->enqueueMessage(
                Text::sprintf('PLG_CONTENT_PNKRINDEXNOW_MSG_ERROR_EXCEPTION', $e->getMessage()),
                'error'
            );
            $this->logDebug('IndexNow notification exception: ' . $e->getMessage());
        }
    }

    /**
     * Log debug messages if debug mode is enabled
     *
     * @param   string  $message  The message to log
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function logDebug(string $message): void
    {
        if ($this->params->get('debug_mode', 0)) {
            $this->getApplication()->enqueueMessage(
                '[IndexNow Plugin] ' . $message,
                'info'
            );
        }
    }

    /**
     * Ensure the IndexNow key file exists
     *
     * @return  bool  True if the key file exists or was created successfully
     *
     * @since   1.0.0
     */
    private function ensureKeyFile(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return false;
        }

        // Security: Validate API key format - only alphanumeric characters, dashes, underscores
        // IndexNow keys are typically 32-128 character hexadecimal or alphanumeric strings
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $apiKey)) {
            $this->logDebug('Invalid API key format. Only alphanumeric characters, dashes, and underscores are allowed.');
            return false;
        }

        // Security: Use basename to prevent path traversal
        $safeFilename = basename($apiKey) . '.txt';
        $keyFile = JPATH_ROOT . '/' . $safeFilename;
        
        // Security: Verify the resolved path is still under JPATH_ROOT
        $realKeyFile = realpath(dirname($keyFile)) . '/' . basename($keyFile);
        if (strpos($realKeyFile, realpath(JPATH_ROOT)) !== 0) {
            $this->logDebug('Security: Key file path is outside root directory');
            return false;
        }
        
        if (!file_exists($keyFile)) {
            return (bool) file_put_contents($keyFile, $apiKey);
        }
        
        return true;
    }
}
