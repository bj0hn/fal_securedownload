<?php

namespace BeechIt\FalSecuredownload\Hooks;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Frans Saris <frans@beech.it>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use BeechIt\FalSecuredownload\Configuration\ExtensionConfiguration;
use BeechIt\FalSecuredownload\Context\UserAspect;
use BeechIt\FalSecuredownload\Events\BeforeFileDumpEvent;
use BeechIt\FalSecuredownload\Events\BeforeRedirectsEvent;
use BeechIt\FalSecuredownload\Security\CheckPermissions;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\AbstractApplication;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\Hook\FileDumpEIDHookInterface;
use TYPO3\CMS\Core\Resource\ResourceInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * FileDumpHook
 */
class FileDumpHook extends AbstractApplication implements FileDumpEIDHookInterface
{

    /**
     * @var FrontendUserAuthentication
     */
    protected $feUser;

    /**
     * @var File
     */
    protected $originalFile;

    /**
     * @var string
     */
    protected $loginRedirectUrl;

    /**
     * @var string
     */
    protected $noAccessRedirectUrl;

    /**
     * @var bool
     */
    protected $forceDownload = false;

    /**
     * @var string
     */
    protected $forceDownloadForExt = '';

    /**
     * @var bool
     */
    protected $resumableDownload = false;

    protected $context;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * Constructor
     */
    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->context = GeneralUtility::makeInstance(Context::class);
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_securedownload']['login_redirect_url'])) {
            $this->loginRedirectUrl = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_securedownload']['login_redirect_url'];
        }
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_securedownload']['no_access_redirect_url'])) {
            $this->noAccessRedirectUrl = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_securedownload']['no_access_redirect_url'];
        }

        if (ExtensionConfiguration::loginRedirectUrl()) {
            $this->loginRedirectUrl = ExtensionConfiguration::loginRedirectUrl();
        }
        if (ExtensionConfiguration::noAccessRedirectUrl()) {
            $this->noAccessRedirectUrl = ExtensionConfiguration::noAccessRedirectUrl();
        }
        $this->forceDownload = ExtensionConfiguration::forceDownload();
        if (ExtensionConfiguration::forceDownloadForExt()) {
            $this->forceDownloadForExt = ExtensionConfiguration::forceDownloadForExt();
        }
        $this->resumableDownload = ExtensionConfiguration::resumableDownload();
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Get feUser
     *
     * @return FrontendUserAuthentication
     */
    public function getFeUser()
    {
        return $this->feUser;
    }

    /**
     * Perform custom security/access when accessing file
     * Method should issue 403 if access is rejected
     * or 401 if authentication is required
     *
     * @param ResourceInterface $file
     */
    public function checkFileAccess(ResourceInterface $file)
    {
        if (!$file instanceof FileInterface) {
            throw new \RuntimeException('Given $file is not a file.', 1469019515);
        }
        if (method_exists($file, 'getOriginalFile')) {
            $this->originalFile = $file->getOriginalFile();
        } else {
            $this->originalFile = $file;
        }

        $loginRedirectUrl = $this->loginRedirectUrl;
        $noAccessRedirectUrl = $this->noAccessRedirectUrl;

        /** @var BeforeRedirectsEvent $event */
        $event = $this->eventDispatcher->dispatch(new BeforeRedirectsEvent($loginRedirectUrl, $noAccessRedirectUrl, $file, $this));
        $loginRedirectUrl = $event->getLoginRedirectUrl();
        $noAccessRedirectUrl = $event->getNoAccessRedirectUrl();

        if (!$this->checkPermissions()) {
            if (!$this->isLoggedIn()) {
                if ($loginRedirectUrl !== null) {
                    $this->redirectToUrl($loginRedirectUrl);
                } else {
                    $this->exitScript('Authentication required!');
                }
            } else {
                if ($noAccessRedirectUrl !== null) {
                    $this->redirectToUrl($noAccessRedirectUrl);
                } else {
                    $this->exitScript('No access!');
                }
            }
        }
        $this->eventDispatcher->dispatch(new BeforeFileDumpEvent($file, $this));

        if (ExtensionConfiguration::trackDownloads()) {
            $columns = [
                'tstamp' => time(),
                'crdate' => time(),
                'feuser' => (int)$this->feUser->user['uid'],
                'file' => (int)$this->originalFile->getUid()
            ];

            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_falsecuredownload_download')
                ->insert(
                    'tx_falsecuredownload_download',
                    $columns,
                    [\PDO::PARAM_INT, \PDO::PARAM_INT, \PDO::PARAM_INT, \PDO::PARAM_INT]
                );
        }

        // Dump the precise requested file for File and ProcessedFile, but dump the referenced file for FileReference
        $dumpFile = $file instanceof FileReference ? $file->getOriginalFile() : $file;

        if ($this->forceDownload($dumpFile->getExtension())) {
            $this->dumpFileContents($dumpFile, true, $this->resumableDownload);
        } elseif ($this->resumableDownload) {
            $this->dumpFileContents($dumpFile, false, true);
        }
    }

    /**
     * Dump file contents
     *
     * @todo: find a nicer way to force the download. Other hooks are blocked by this.
     * @todo: Try to get the resumable option part of TYPO3 core itself
     *
     * @param File $file
     * @param bool $asDownload
     * @param bool $resumableDownload
     */
    protected function dumpFileContents($file, $asDownload, $resumableDownload)
    {
        $downloadName = $file->hasProperty('download_name') ? $file->getProperty('download_name') : $file->getName();

        // Make sure downloadName has a file extension
        $fileParts = pathinfo($downloadName);
        if (empty($fileParts['extension'])) {
            $downloadName .= '.' . $file->getExtension();
        }

        if (!$resumableDownload) {
            $response = $file->getStorage()->streamFile($file, $asDownload, $downloadName);
            $this->sendResponse($response);
            exit;
        }

        $contentDisposition = $asDownload ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $contentDisposition . '; filename="' . $downloadName . '"');
        header('Content-Type: ' . $file->getMimeType());
        header('Expires: -1');
        header('Cache-Control: public, must-revalidate, post-check=0, pre-check=0');

        $fileSize = $file->getSize();
        $range = $this->getHttpRange($fileSize);
        if ($range === []) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        // Find part of file and push this out
        $filePointer = @fopen($file->getForLocalProcessing(false), 'rb');
        if ($filePointer === false) {
            header('HTTP/1.1 404 File not found');
            exit;
        }

        $dumpSize = $fileSize;
        list($begin, $end) = $range;
        if ($begin !== 0 || $end !== $fileSize - 1) {
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $begin . '-' . $end . '/' . $fileSize);
            $dumpSize = $end - $begin + 1;
        }
        header('Content-Length: ' . $dumpSize);
        header('Accept-Ranges: bytes');

        ob_clean();
        flush();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        fseek($filePointer, $begin);
        $dumpedSize = 0;
        while (!feof($filePointer) && $dumpedSize < $dumpSize) {
            $partSize = 1024 * 8;
            if ($partSize > $dumpSize - $dumpedSize) {
                $partSize = $dumpSize - $dumpedSize;
            }
            $buffer = @fread($filePointer, $partSize);
            $dumpedSize += strlen($buffer);
            print $buffer;
            ob_flush();
            flush();

            if (connection_status() !== 0) {
                break;
            }
        }

        @fclose($filePointer);
        exit;
    }

    /**
     * Determine if we want to force a file download
     *
     * @param string $fileExtension
     * @return bool
     */
    protected function forceDownload($fileExtension)
    {
        $forceDownload = false;
        if ($this->forceDownload) {
            $forceDownload = true;
        } elseif (isset($_REQUEST['download'])) {
            $forceDownload = true;
        } elseif (GeneralUtility::inList(str_replace(' ', '', $this->forceDownloadForExt), $fileExtension)) {
            $forceDownload = true;
        }

        return $forceDownload;
    }

    /**
     * Check if user is logged in
     *
     * @return bool
     */
    protected function isLoggedIn()
    {
        $this->initializeUserAuthentication();
        return is_array($this->feUser->user) && $this->feUser->user['uid'] ? true : false;
    }

    /**
     * Check if current user has enough permissions to view file
     *
     * @return bool
     */
    protected function checkPermissions()
    {
        $this->initializeUserAuthentication();

        /** @var $checkPermissionsService CheckPermissions */
        $checkPermissionsService = GeneralUtility::makeInstance(CheckPermissions::class);

        if ($checkPermissionsService->checkBackendUserFileAccess($this->originalFile)) {
            return true;
        }

        $userFeGroups = !$this->feUser->user ? false : $this->feUser->groupData['uid'];

        return $checkPermissionsService->checkFileAccess($this->originalFile, $userFeGroups);
    }

    /**
     * Initialise feUser
     */
    protected function initializeUserAuthentication()
    {
        if ($this->feUser === null) {
            /** @var UserAspect $userAspect */
            $userAspect = $this->context->getAspect('beechit.user');
            $this->feUser = $userAspect->get('user');
            $this->feUser->fetchGroupData();
        }
    }

    /**
     * Exit with a error message
     *
     * @param string $message
     * @param int $httpCode
     */
    protected function exitScript($message, $httpCode = 403)
    {
        header('HTTP/1.1 ' . (int)$httpCode . ' Forbidden');
        exit($message);
    }

    /**
     * Redirect to url
     *
     * @param $url
     */
    protected function redirectToUrl($url)
    {
        $url = str_replace(
            '###REQUEST_URI###',
            rawurlencode(GeneralUtility::getIndpEnv('REQUEST_URI')),
            $url
        );

        if (stripos($url, 't3://') === 0) {
            $url = $this->resolveUrl($url);
        }

        header('location: ' . $url);
        exit;
    }

    /**
     * Resolve the URL (currently only page and external URL are supported)
     *
     * @param string $url
     */
    protected function resolveUrl($url): string
    {
        $urlParameters = GeneralUtility::makeInstance(LinkService::class)->resolve($url);

        if ($urlParameters['type'] !== LinkService::TYPE_PAGE && $urlParameters['type'] !== LinkService::TYPE_URL) {
            throw new InvalidArgumentException(
                'Redirects URL can only handle TYPO3 urls of types "page" or "url".',
                1522826609
            );
        }

        if ($urlParameters['type'] === LinkService::TYPE_URL) {
            $uri = $urlParameters['url'];
        } else {
            $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class, null);
            $contentObject->start([], '');

            $uri = $contentObject->typoLink_URL([
                'addQueryString' => true,
                'addQueryString.' => [
                    'exclude' => 'eID,f,t,token'
                ],
                'forceAbsoluteUrl' => true,
                'parameter' => $url,
                'returnLast' => 'url'
            ]);
        }

        return (string)$uri;
    }

    /**
     * Determines the HTTP range given in the request
     *
     * @param int $fileSize the size of the file
     * @return array the range (begin, end), or empty array if the range request is invalid.
     */
    protected function getHttpRange($fileSize)
    {
        $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : false;
        if (!$range || $range === '-') {
            return [0, $fileSize - 1];
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            return [];
        }
        if ($matches[1] === '') {
            $start = $fileSize - $matches[2];
            $end = $fileSize - 1;
        } elseif ($matches[2] !== '') {
            $start = $matches[1];
            $end = $matches[2];
            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
        } else {
            $start = $matches[1];
            $end = $fileSize - 1;
        }
        if ($start < 0 || $start > $end) {
            return false;
        }
        return [$start, $end];
    }
}
