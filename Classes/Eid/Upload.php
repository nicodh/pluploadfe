<?php

namespace TYPO3\Pluploadfe\Eid;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011-2017 Felix Nagel <info@felixnagel.com>
 *  (c) 2016 Daniel Wagner
 *
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

if (!defined('PATH_typo3conf')) {
    die();
}

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;
use TYPO3\Pluploadfe\Utility\FileValidation;

/**
 * This class uploads files.
 *
 * @todo translate error messages
 */
class Upload
{
    /**
     * @var bool
     */
    private $chunkedUpload = false;

    /**
     * @var \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication
     */
    private $feUserObj = null;

    /**
     * @var array
     */
    private $config = array();

    /**
     * @var string
     */
    private $uploadPath = '';

    /**
     * Handles incoming upload requests.
     */
    public function main()
    {
        $this->setHeaderData();

        // get configuration record
        $this->config = $this->getUploadConfig();
        $this->processConfig();
        $this->checkUploadConfig();

        // check for valid FE user
        if ($this->config['feuser_required']) {
            if ($this->getFeUser()->user['username'] == '') {
                $this->sendErrorResponse('TYPO3 user session expired.');
            }
        }

        // One file or chunked?
        $this->chunkedUpload = (isset($_REQUEST['chunks']) && intval($_REQUEST['chunks']) > 1);

        // check file extension
        $this->checkFileExtension();

        // get upload path
        $this->uploadPath = $this->getUploadDir(
            $this->config['upload_path'],
            $this->getUserDirectory(),
            $this->config['obscure_dir']
        );
        $this->makeSureUploadTargetExists();

        $this->uploadFile();
    }

    /**
     * Get FE user object.
     *
     * @return \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication
     */
    protected function getFeUser()
    {
        if ($this->feUserObj === null) {
            $this->feUserObj = EidUtility::initFeUser();
        }

        return $this->feUserObj;
    }

    /**
     * Get sub directory based upon user data.
     *
     * @return string
     */
    protected function getUserDirectory()
    {
        $record = $this->getFeUser()->user;
        $field = $this->config['feuser_field'];

        switch ($field) {
            case 'name':
            case 'username':
                $directory = $record[$field];
                break;

            case 'fullname':
                $parts = array($record['first_name'], $record['middle_name'], $record['last_name']);
                $directory = implode('_', array_values(array_filter($parts)));
                break;

            case 'uid':
            case 'pid':
                $directory = (string) $record[$field];
                break;

            case 'lastlogin':
                try {
                    $date = new \DateTime('@'.$record[$field]);
                    $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    $directory = strftime('%Y%m%d-%H', $date->format('U'));
                } catch (\Exception $exception) {
                    $directory = 'checkTimezone';
                }
                break;

            default:
                $directory = '';
        }

        return preg_replace('/[^0-9a-zA-Z\-\.]/', '_', $directory);
    }

    /**
     * Set HTTP headers for no cache etc.
     */
    protected function setHeaderData()
    {
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }

    /**
     * Set HTTP headers for no cache etc.
     *
     * @param $message
     * @param int $code
     */
    protected function sendErrorResponse($message, $code = 100)
    {
        $output = array(
            'jsonrpc' => '2.0',
            'error' => array(
                'code' => $code,
                'message' => $message,
            ),
            'id' => '',
        );

        die(json_encode($output));
    }

    /**
     * Gets the plugin configuration.
     */
    protected function checkUploadConfig()
    {
        if (!count($this->config)) {
            $this->sendErrorResponse('Configuration record not found or invalid.');
        }

        if (!strlen($this->config['extensions'])) {
            $this->sendErrorResponse('Missing allowed file extension configuration.');
        }

        if (!$this->checkPath($this->config['upload_path'])) {
            $this->sendErrorResponse('Upload directory not valid.');
        }
    }

    /**
     * Gets the plugin configuration.
     *
     * @return array
     */
    protected function getUploadConfig()
    {
        $configUid = intval(GeneralUtility::_GP('configUid'));

        // config id given?
        if (!$configUid) {
            $this->sendErrorResponse('No config record ID given.');
        }

        $select = 'upload_path, extensions, feuser_required, feuser_field, save_session, obscure_dir, check_mime';
        $table = 'tx_pluploadfe_config';
        $where = 'uid = '.$configUid;
        $where .= ' AND deleted = 0';
        $where .= ' AND hidden = 0';
        $where .= ' AND starttime <= '.$GLOBALS['SIM_ACCESS_TIME'];
        $where .= ' AND ( endtime = 0 OR endtime > '.$GLOBALS['SIM_ACCESS_TIME'].')';

        $config = $this->getDatabase()->exec_SELECTgetSingleRow($select, $table, $where);

        return $config;
    }

    /**
     * Process the configuration.
     *
     * @return void
     */
    protected function processConfig()
    {
        // Make sure FAL references work
        $resourceFactory = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance();
        $this->config['upload_path'] = $resourceFactory
            ->retrieveFileOrFolderObject($this->config['upload_path'])
            ->getPublicUrl();

        // Make sure no user based path is added when there is no user available
        if (!$this->config['feuser_required']) {
            $this->config['feuser_field'] = '';
        }
    }

    /**
     * Check if path is allowed and valid.
     *
     * @param $path
     *
     * @return bool
     */
    protected function checkPath($path)
    {
        return (strlen($path) > 0 && GeneralUtility::isAllowedAbsPath(PATH_site.$path));
    }

    /**
     * Checks file extension.
     *
     * Script ends here when bad filename is given.
     */
    protected function checkFileExtension()
    {
        $fileName = $this->getFileName();
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $extensions = GeneralUtility::trimExplode(',', $this->config['extensions'], true);

        // check if file extension is allowed (configuration record)
        if (!in_array($fileExtension, $extensions)) {
            $this->sendErrorResponse('File extension is not allowed.');
        }

        // check if file extension is allowed on this TYPO3 installation
        if (!GeneralUtility::verifyFilenameAgainstDenyPattern($fileName)) {
            $this->sendErrorResponse('File extension is not allowed on this TYPO3 installation.');
        }
    }

    /**
     * Gets the uploaded file name from request.
     *
     * @return string
     */
    protected function getFileName()
    {
        $filename = uniqid('file_');

        if (isset($_REQUEST['name'])) {
            $filename = $_REQUEST['name'];
        } elseif (!empty($_FILES)) {
            $filename = $_FILES['file']['name'];
        }

        return preg_replace('/[^\w\._]+/', '_', $filename);
    }

    /**
     * Checks and creates the upload directory.
     *
     * @param string $path
     * @param string $subDirectory
     * @param bool   $obscure
     *
     * @return string
     */
    protected function getUploadDir($path, $subDirectory = '', $obscure = false)
    {
        if ($this->chunkedUpload) {
            $chunkedPath = $this->getSessionData('chunk_path');
            if ($chunkedPath && file_exists($chunkedPath.DIRECTORY_SEPARATOR.$this->getFileName().'.part')) {
                return $chunkedPath;
            } else {
                // reset session
                $this->saveDataInSession(null, 'chunk_path');
            }
        }

        // make sure we have no trailing slash
        $path = GeneralUtility::dirname($path);

        // subdirectory
        if ($subDirectory) {
            $path = $path.DIRECTORY_SEPARATOR.$subDirectory;
        }

        // obscure directory
        if ($obscure) {
            $path = $path.DIRECTORY_SEPARATOR.$this->getRandomDirName(20);
        }

        return $path;
    }

    /**
     * Checks if upload path exists.
     */
    protected function makeSureUploadTargetExists()
    {
        if (file_exists($this->uploadPath)) {
            return;
        }

        // create target dir
        try {
            GeneralUtility::mkdir_deep(PATH_site, $this->uploadPath);
        } catch (\Exception $e) {
            $this->sendErrorResponse('Failed to create upload directory.');
        }
    }

    /**
     * Handles file upload.
     *
     * Copyright 2013, Moxiecode Systems AB
     * Released under GPL License.
     *
     * License: http://www.plupload.com/license
     * Contributing: http://www.plupload.com/contributing
     */
    protected function uploadFile()
    {
        // Get additional parameters
        $chunk = isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0;
        $chunks = isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0;

        // Clean the fileName for security reasons
        $filePath = $this->uploadPath.DIRECTORY_SEPARATOR.$this->getFileName();

        // Open temp file
        if (!$out = @fopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
            $this->sendErrorResponse('Failed to open output stream.', 102);
        }

        if (!empty($_FILES)) {
            if ($_FILES['file']['error'] || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                $this->sendErrorResponse('Failed to move uploaded file.', 103);
            }

            // Read binary input stream and append it to temp file
            if (!$in = @fopen($_FILES['file']['tmp_name'], 'rb')) {
                $this->sendErrorResponse('Failed to open input stream.', 101);
            }
        } else {
            if (!$in = @fopen('php://input', 'rb')) {
                $this->sendErrorResponse('Failed to open input stream.', 101);
            }
        }

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }

        @fclose($out);
        @fclose($in);

        // Check if file has been uploaded
        if (!$chunks || $chunk == $chunks - 1) {
            // Strip the temp .part suffix off
            rename($filePath.'.part', $filePath);
            $this->processFile($filePath);
        }

        // save chunked upload dir
        if ($this->chunkedUpload) {
            $this->saveDataInSession($this->uploadPath, 'chunk_path');
        }

        // Return JSON-RPC response if upload process is successfully finished
        die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
    }

    /**
     * Process uploaded file.
     *
     * @param string $filePath
     *
     * @params string $filePath
     */
    protected function processFile($filePath)
    {
        if ($this->config['check_mime']) {
            // we already checked if the file extension is allowed,
            // so we need to check if the mime type is adequate.
            // if mime type is not allowed: remove file
            if (!FileValidation::checkMimeType($filePath)) {
                @unlink($filePath);
                $this->sendErrorResponse('File mime type is not allowed.');
            }
        }

        GeneralUtility::fixPermissions($filePath);

        if ($this->config['save_session']) {
            $this->saveFileInSession($filePath);
        }
    }

    /**
     * Store file in session.
     *
     * @param string $filePath
     * @param string $key
     */
    protected function saveFileInSession($filePath, $key = 'files')
    {
        $currentData = $this->getSessionData($key);

        if (!is_array($currentData)) {
            $currentData = array();
        }

        $currentData[] = $filePath;

        $this->saveDataInSession($currentData, $key);
    }

    /**
     * Store session data.
     *
     * @param mixed  $data
     * @param string $key
     */
    protected function saveDataInSession($data, $key = 'data')
    {
        $this->getFeUser()->setAndSaveSessionData('tx_pluploadfe_'.$key, $data);
    }

    /**
     * Get session data.
     *
     * @param string $key
     *
     * @return mixed
     */
    protected function getSessionData($key = 'data')
    {
        return $this->getFeUser()->getSessionData('tx_pluploadfe_'.$key);
    }

    /**
     * Generate random string.
     *
     * @param int $length
     *
     * @return string
     */
    protected function getRandomDirName($length = 10)
    {
        $set = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIKLMNPQRSTUVWXYZ0123456789';
        $string = '';

        for ($i = 1; $i <= $length; ++$i) {
            $string .= $set[mt_rand(0, (strlen($set) - 1))];
        }

        return $string;
    }

    /**
     * Get database connection.
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabase()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}

/** @noinspection PhpUndefinedVariableInspection */
if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/pluploadfe/Classes/Eid/Upload.php']) {
    /** @noinspection PhpUndefinedVariableInspection */
    include_once $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/pluploadfe/Classes/Eid/Upload.php'];
}

if (!(TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_FE)) {
    die();
} else {
    $upload = GeneralUtility::makeInstance(Upload::class);
    $upload->main();
}