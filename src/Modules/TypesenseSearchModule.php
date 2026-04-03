<?php

namespace Krabo\TypesenseSearchBundle\Modules;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Contao\Module;
use Contao\StringUtil;
use Contao\System;
use Haste\Util\Debug;
use Krabo\TypesenseSearchBundle\Typesense;

class TypesenseSearchModule extends Module
{

    protected $strTemplate = 'mod_typesense_search';

    public function generate()
    {

        if (\System::getContainer()->get('request_stack')->getCurrentRequest()->get('_scope') == 'backend') {

            $objTemplate = new \BackendTemplate('be_wildcard');
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->title = $this->headline;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;
            $objTemplate->wildcard = '### ' . strtoupper($GLOBALS['TL_LANG']['FMD']['elasticsearch_type_ahead'][0]) . ' ###';

            return $objTemplate->parse();
        }

        return parent::generate();
    }

    protected function compile()
    {
        global $objPage;
        $typesense = Typesense::getInstance();

        $strKeywords = trim(Input::get('keywords'));

        $this->Template->TypesenseEnabled = $typesense->isEnabled();
        $this->Template->uniqueId = $this->id;
        $this->Template->rootPageId = $objPage->rootId;
        $this->Template->redirect = $this->getRedirectUrl();
        $this->Template->isResultPage = $this->isResultsPage();
        $this->Template->keywordLabel = $GLOBALS['TL_LANG']['MSC']['keywords'];
        $this->Template->search = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['searchLabel']);
        $this->Template->didYouMeanLabel = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['didYouMeanLabel']);
        $this->Template->config = $typesense->getClientConfiguration();
        $this->Template->debug = System::getContainer()->get('kernel')->isDebug();

        $collections = [];
        foreach(StringUtil::deserialize($this->typesense_collections) as $collectionId) {
          $objResults = $this->Database->prepare("SELECT * FROM `tl_typesense_collection` WHERE `id` = ?")->execute($collectionId);
          $collection = $objResults->first()->row();
          $collection['query_settings'] = StringUtil::decodeEntities($collection['query_settings']);
          $collection['header_template'] = StringUtil::decodeEntities($collection['header_template']);
          $collection['item_template'] = StringUtil::decodeEntities($collection['item_template']);
          $collection['footer_template'] = StringUtil::decodeEntities($collection['footer_template']);
          $collection['no_results_template'] = StringUtil::decodeEntities($collection['no_results_template']);
          $collections[] = $collection;
        }
        $this->Template->collections = $collections;

        $this->Template->keyword = StringUtil::specialchars($strKeywords);
        $this->Template->action = $this->getActionUrl();
    }

    protected function isResultsPage()
    {

        global $objPage;

        return $objPage->id === $this->jumpTo;
    }

    protected function getRedirectUrl()
    {

        $strRedirect = '';

        if ($objPage = \PageModel::findByPk($this->jumpTo)) {
            $strRedirect = $objPage->getFrontendUrl();
        }

        return $strRedirect;
    }

    protected function getActionUrl()
    {

        if ($objJump = \PageModel::findByPk($this->jumpTo)) {
            return $objJump->getFrontendUrl();
        }

        global $objPage;
        return $objPage->getFrontendUrl();
    }
}