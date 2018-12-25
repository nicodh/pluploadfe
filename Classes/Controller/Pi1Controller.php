<?php

namespace FelixNagel\Pluploadfe\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011-2018 Felix Nagel <info@felixnagel.com>
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

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\Plugin\AbstractPlugin;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
/**
 * Plugin 'pluploadfe_pi1' for the 'pluploadfe' extension.
 */
class Pi1Controller extends AbstractPlugin
{
    /**
     * @var string
     */
    public $prefixId = 'tx_pluploadfe_pi1';

    /**
     * @var string
     */
    public $scriptRelPath = 'Classes/Controller/Pi1Controller.php';

    /**
     * @var string
     */
    public $extKey = 'pluploadfe';

    /**
     * @var bool
     */
    public $pi_checkCHash = true;

    /**
     * @var int
     */
    protected $configUid;

    /**
     * @var int
     */
    protected $uid;

    /**
     * @var string
     */
    protected $templateDir;

    /**
     * @var array
     */
    protected $config;

    /**
     * The main method of the PlugIn.
     *
     * @param string $content : The plugin content
     * @param array  $conf    : The plugin configuration
     *
     * @return string The content that is displayed on the website
     */
    public function main($content, $conf)
    {
        $this->conf = $conf;
        $this->pi_setPiVarDefaults();
        $this->pi_loadLL('EXT:pluploadfe/Resources/Private/Language/locallang.xml');

        // set (localized) UID
        $localizedUid = $this->cObj->data['_LOCALIZED_UID'];
        if (strlen($this->conf['uid']) > 0) {
            $this->uid = $this->conf['uid'];
        } else {
            $this->uid = intval(($localizedUid) ? $localizedUid : $this->cObj->data['uid']);
        }

        // set config record uid
        if (strlen($this->conf['configUid']) > 0) {
            $this->configUid = $this->conf['configUid'];
        } else {
            $this->configUid = intval($this->cObj->data['tx_pluploadfe_config']);
        }

        $this->getUploadConfig();

        $this->templateDir = (strlen(trim($this->conf['templateDir'])) > 0) ?
                    trim($this->conf['templateDir']) : 'EXT:pluploadfe/Resources/Private/Templates/';

        if ($this->checkConfig()) {
            $this->renderCode();
            $content = $this->getHtml();
        } else {
            $content = '<div style="border: 3px solid red; padding: 1em;">
			<strong>TYPO3 EXT:plupload Error</strong><br />Invalid configuration.</div>';
        }

        return $this->pi_wrapInBaseClass($content);
    }

    /**
     * Checks config.
     */
    protected function getUploadConfig()
    {
        $select = 'extensions';
        $table = 'tx_pluploadfe_config';

        $qb = $this->getDatabase()->getQueryBuilderForTable($table);
        $statement = $qb
            ->select($select)
           ->from($table)
           ->where(
               $qb->expr()->eq('uid', $qb->createNamedParameter($this->configUid, \PDO::PARAM_INT))
           );
        $this->config = $statement->execute()->fetch();
    }

    /**
     * Checks config.
     *
     * @return bool
     */
    protected function checkConfig()
    {
        $flag = false;

        if (strlen($this->uid) > 0 &&
            strlen($this->templateDir) > 0 &&
            intval($this->configUid) > 0 &&
            is_array($this->config) &&
            strlen($this->config['extensions']) > 0
        ) {
            $flag = true;
        } else {
            $this->handleError('Invalid configuration');
        }

        return $flag;
    }

    /**
     * Function to parse the template.
     */
    protected function renderCode()
    {
        // fill marker array
        $markerArray = $this->getDefaultMarker();
        $markerArray['UPLOAD_FILE'] = GeneralUtility::getIndpEnv('TYPO3_SITE_URL').
            'index.php?eID=pluploadfe&configUid='.$this->configUid;
        /* @var $standaloneView \TYPO3\CMS\Fluid\View\StandaloneView */
        $standaloneView = GeneralUtility::makeInstance(StandaloneView::class);
        $standaloneView->assignMultiple($markerArray);
        $standaloneView->setTemplatePathAndFilename($this->templateDir . 'fluid_template.html');
        $content = $standaloneView->render();
        $this->getPageRenderer()->addJsFooterInlineCode(
            $this->prefixId.'_'.$this->uid,
            $content
        );
    }

    /**
     * Function to parse the template.
     *
     * @return string
     */
    protected function getHtml()
    {
        // fill marker array
        $markerArray = $this->getDefaultMarker();
        $markerArray['INFO_1'] = $this->pi_getLL('info_1');
        $markerArray['INFO_2'] = $this->pi_getLL('info_2');
        $standaloneView = GeneralUtility::makeInstance(StandaloneView::class);
        $standaloneView->assignMultiple($markerArray);
        $standaloneView->setTemplatePathAndFilename($this->templateDir . 'fluid_content_template.html');
        return $standaloneView->render();
    }

    /**
     * Function to render the default marker.
     *
     * @return array
     */
    protected function getDefaultMarker()
    {
        $markerArray = array();
        $extensionsArray = GeneralUtility::trimExplode(',', $this->config['extensions'], true);
        $maxFileSizeInBytes = GeneralUtility::getMaxUploadFileSize() * 1024;

        $markerArray['UID'] = $this->uid;
        $markerArray['LANGUAGE'] = $this->getTsFeController()->config['config']['language'];
        $markerArray['EXTDIR_PATH'] = GeneralUtility::getIndpEnv('TYPO3_SITE_URL').
            ExtensionManagementUtility::siteRelPath($this->extKey);
        $markerArray['FILE_EXTENSIONS'] = implode(',', $extensionsArray);
        $markerArray['FILE_MAX_SIZE'] = $maxFileSizeInBytes;

        return $markerArray;
    }


    /**
     * Get page renderer.
     *
     * @return \TYPO3\CMS\Core\Page\PageRenderer
     */
    public static function getPageRenderer()
    {
        /* @var $pageRenderer \TYPO3\CMS\Core\Page\PageRenderer */
        $pageRenderer = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class);

        return $pageRenderer;
    }

    /**
     * Handles error output for frontend and TYPO3 logging.
     *
     * @param string$msg Message to output
     */
    protected function handleError($msg)
    {

        // write dev log if enabled
        if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['enable_DLOG']) {
            // fatal error
            GeneralUtility::devLog($msg, $this->extKey, 3);
        }
    }

    /**
     * Get database connection.
     *
     * @return ConnectionPool
     */
    protected function getDatabase()
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected static function getTsFeController()
    {
        return $GLOBALS['TSFE'];
    }
}

/** @noinspection PhpUndefinedVariableInspection */
if (defined('TYPO3_MODE') &&
    $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/pluploadfe/Classes/Controller/Pi1Controller.php']
) {
    /** @noinspection PhpUndefinedVariableInspection */
    include_once $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/pluploadfe/Classes/Controller/Pi1Controller.php'];
}
