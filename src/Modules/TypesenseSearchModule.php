<?php

namespace Krabo\TypesenseSearchBundle\Modules;

use Contao\Input;
use Contao\Module;
use Contao\StringUtil;
use Contao\System;
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
          $collection['header_advanced_template'] = StringUtil::decodeEntities($collection['header_advanced_template']);
          $collection['item_template'] = StringUtil::decodeEntities($collection['item_template']);
          $collection['item_advanced_template'] = StringUtil::decodeEntities($collection['item_advanced_template']);
          $collection['footer_template'] = StringUtil::decodeEntities($collection['footer_template']);
          $collection['footer_advanced_template'] = StringUtil::decodeEntities($collection['footer_advanced_template']);
          $collection['no_results_template'] = StringUtil::decodeEntities($collection['no_results_template']);
          if ($typesense->doesCollectionExist($collection['name'])) {
            $collections[] = $collection;
          }
        }
        $this->Template->collections = $collections;
        $this->Template->keyword = StringUtil::specialchars($strKeywords);
    }
}