<?php

namespace Krabo\TypesenseSearchBundle\Modules;

use Contao\Module;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Krabo\TypesenseSearchBundle\Typesense;

abstract class AbstractTypesenseModule extends Module
{

  protected function compile()
  {
    $typesense = Typesense::getInstance();

    $this->Template->TypesenseEnabled = $typesense->isEnabled();
    $this->Template->search = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['searchLabel']);
    $this->Template->config = $typesense->getClientConfiguration();
    $this->Template->debug = System::getContainer()->get('kernel')->isDebug();

    $collections = [];
    $i = 0;
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
      $collection['instantsearch_query_settings'] = StringUtil::decodeEntities($collection['instantsearch_query_settings']);
      $collection['instantsearch_stats_template'] = StringUtil::decodeEntities($collection['instantsearch_stats_template']);
      $collection['instantsearch_stats_advanced_template'] = StringUtil::decodeEntities($collection['instantsearch_stats_advanced_template']);
      $collection['instantsearch_item_template'] = StringUtil::decodeEntities($collection['instantsearch_item_template']);
      $collection['instantsearch_item_advanced_template'] = StringUtil::decodeEntities($collection['instantsearch_item_advanced_template']);
      $collection['instantsearch_no_results_template'] = StringUtil::decodeEntities($collection['instantsearch_no_results_template']);

      if ($typesense->doesCollectionExist($collection['name'])) {
        $collections[$i] = $collection;
        $i++;
      }
    }
    $this->Template->collections = $collections;

    if (($objTarget = $this->objModel->getRelated('typesense_offline_jumpTo')) instanceof PageModel)
    {
      /** @var PageModel $objTarget */
      $this->Template->offline_url = $objTarget->getAbsoluteUrl();
    }
  }
}