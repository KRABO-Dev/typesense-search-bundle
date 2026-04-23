<?php
/**
 * Copyright (C) 2026  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Krabo\TypesenseSearchBundle\Helper;

use Contao\Controller;
use Contao\Database;
use Contao\Environment;
use Contao\FilesModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Isotope\Isotope;
use Isotope\Model\Config as IsoConfig;
use Isotope\Model\Product;
use Isotope\Model\ProductCollection\Cart;
use Krabo\TypesenseSearchBundle\Typesense;

class IsotopeProductHelper {

  public function removeProductIndexes() {
    static $objConfigs = null;
    if ($objConfigs === null) {
      $objConfigs = IsoConfig::findAll();
    }
    $typesense = Typesense::getInstance();
    foreach ($objConfigs as $objConfig) {
      $typesense->deleteCollection('iso_product_index_' . $objConfig->id);
    }
  }

  public function createProductIndexes() {
    static $objConfigs = null;
    if ($objConfigs === null) {
      $objConfigs = IsoConfig::findAll();
    }
    $typesense = Typesense::getInstance();
    foreach ($objConfigs as $objConfig) {
      $typesense->createCollection('iso_product_index_' . $objConfig->id, $this->getProductCollectionFields(), 'iso_product');
    }
  }

  public function getAvailableLanguages() {
    static $languages = null;
    if ($languages === null) {
      $languages = [];
      $objTranslatedProduct = Database::getInstance()->prepare("SELECT DISTINCT `language` FROM tl_iso_product  WHERE pid > 0 AND language IS NOT NULL")->execute();
      while ($objTranslatedProduct->next()) {
        $languages[] = $objTranslatedProduct->language;
      }
    }
    return $languages;
  }

  public function indexProductDocument(\Isotope\Interfaces\IsotopeProduct $objProduct)
  {
    static $objConfigs = null;
    if ($objConfigs === null) {
      $objConfigs = IsoConfig::findAll();
    }

    foreach($objConfigs as $objConfig)
    {
      $collection = 'iso_product_index_' . $objConfig->id;

      // Override shop configuration to generate correct price
      $objCart = new Cart();
      $objCart->config_id = $objConfig->id;
      Isotope::setConfig($objConfig);
      Isotope::setCart($objCart);

      $this->indexProductDocumentPerConfig($objProduct, $objConfig, $collection);
    }
  }

  public function indexProductDocumentPerConfig(\Isotope\Interfaces\IsotopeProduct $objProduct, $objConfig, string $collection)
  {
    $languages = $this->getAvailableLanguages();
    $typesense = Typesense::getInstance();

    //Refresh all data from the database
    $objProduct->refresh();

    // Get root pages that belong to this store config.
    $arrPages = array();
    $productCategories = \array_map('\intval', $objProduct->getCategories(true));
    $objRoot = PageModel::findBy(array("type='root'", "iso_config"), $objConfig->id);
    if(null !== $objRoot)
    {
      $arrRoots = $objRoot->fetchEach('id');
      $arrPages = Database::getInstance()->getChildRecords($arrRoots, 'tl_page', false, $arrRoots);
    }

    // Get default URL - Check product first and if not fall back to config reader page
    $intJumpTo = $objProduct->feedJumpTo ?: $objConfig->feedJumpTo;

    if(empty($intJumpTo) || $intJumpTo === 0)
    {
      $intJumpTo = reset($productCategories);
    }

    // Ensure the product is published, it's set to be in the feed, it has a price,
    // and it's been set to a category in one of the site roots for this config.
    if ($objProduct->isPublished()
      && $objProduct->getPrice(Isotope::getCart()) !== null
      && !empty(\array_intersect($productCategories, $arrPages))
    ) {
      //Check for variants and run them instead if they exist
      if($objProduct->hasVariants() && !$objProduct->isVariant())
      {
        foreach($objProduct->getVariantIds() as $variantID)
        {
          $objVariant = Product::findPublishedByPk($variantID);
          $this->indexProductDocument($objVariant, $objConfig, $collection);
        }

        $typesense->removeDocumentFromIndex($objProduct->id, $collection);
        return;
      }

      if (($jumpToPage = PageModel::findByPk($intJumpTo)) !== null) {
        $jumpToPage->loadDetails();
      }
      $productUrl = $objProduct->generateUrl($jumpToPage);

      if ($jumpToPage !== null
        && \trim($jumpToPage->domain)
        && ($firstPageModel = PageModel::findFirstPublishedByPid($jumpToPage->rootId)) !== null
      ) {
        $strLink = $firstPageModel->getFrontendUrl();
      }
      // Fall back to the current domain if it's not set here
      if (empty($strLink)) {
        $strLink = Environment::get('base');
      }

      // The product and page model generated an absolute URL!
      if (\stripos($productUrl, 'http') !== 0) {
        $productUrl = $strLink . $productUrl;
      }



      $objItem = [];
      $defaultTitle = $objProduct->name;
      $defaultDescription = $objProduct->description;
      $defaultDescription = System::getContainer()->get('contao.insert_tag.parser')->replaceInline($defaultDescription ?? '');
      $defaultDescription = Controller::convertRelativeUrls($defaultDescription, $strLink);
      $defaultDescription = strip_tags($defaultDescription);

      $objItem['available'] = $objProduct->isAvailableInFrontend();
      $objItem['title'] = StringUtil::decodeEntities($defaultTitle);
      foreach($languages as $language) {
        $objItem['title_' . $language] = '';
      }
      $objItem['url'] = $productUrl;
      $objItem['description'] = StringUtil::decodeEntities($defaultDescription);
      foreach($languages as $language) {
        $objItem['description_' . $language] = '';
      }
      $objItem['sku'] = ($objProduct->sku && strlen($objProduct->sku)) ? $objProduct->sku : $objProduct->alias;
      $objItem['price'] = $objProduct->getPrice(Isotope::getCart())->getAmount();
      $objItem['formatted_price'] = Isotope::formatPriceWithCurrency($objProduct->getPrice(Isotope::getCart())->getAmount(), false);
      $objItem['image_url'] = '';
      $arrImages = $this->getProductImages($objProduct, $objConfig, $strLink);
      if(is_array($arrImages) && count($arrImages)>0)
      {
        $objItem['image_url'] = $arrImages[0];
        unset($arrImages[0]);
      }

      foreach($languages as $language) {
        $objTranslatedProduct = Database::getInstance()->prepare("SELECT * FROM tl_iso_product  WHERE pid = ? AND language = ?")->execute($objProduct->id, $language);
        if ($objTranslatedProduct->first()) {
          $objItem['title_' . $language] = $objTranslatedProduct->name ?? '';
          $objItem['title_' . $language] = StringUtil::decodeEntities($objItem['title_' . $language]);
          $translatedDescription = $objTranslatedProduct->description ?? '';
          $translatedDescription = System::getContainer()->get('contao.insert_tag.parser')->replaceInline($translatedDescription ?? '');
          $translatedDescription = Controller::convertRelativeUrls($translatedDescription, $strLink);
          $translatedDescription = strip_tags($translatedDescription);
          $objItem['description_' . $language] = StringUtil::decodeEntities($translatedDescription);
        }
      }

      $typesense->indexDocument($objItem, $objProduct->id, $collection, 'iso_product', ['id' => $objProduct->id, 'objProduct' => $objProduct]);
    }
    else
    {
      $typesense->removeDocumentFromIndex($objProduct->id, $collection);
    }
  }

  protected function getProductImages(\Isotope\Interfaces\IsotopeProduct $objProduct, $objConfig, $strLink)
  {
    $arrImages = array();
    $varValue = StringUtil::deserialize($objProduct->images);
    $projectDir = System::getContainer()->getParameter('kernel.project_dir');

    if(is_array($varValue) && count($varValue))
    {
      foreach( $varValue as $k => $file )
      {
        $strFile = $file['src'];

        // File without path must be located in the isotope root folder
        if (strpos($strFile, '/') === false) {
          $strFile = 'isotope/' . strtolower(substr($strFile, 0, 1)) . '/' . $strFile;
        }

        if (is_file($projectDir . '/' . $strFile))
        {
          $arrImages[] = $strLink . $strFile;
        }
      }
    }

    // No image available, add placeholder from store configuration
    if (empty($arrReturn)) {
      $objPlaceholder = FilesModel::findByPk($objConfig->placeholder);
      if (null !== $objPlaceholder && is_file($projectDir . '/' . $objPlaceholder->path)) {
        $arrImages[] = $strLink . $objPlaceholder->path;
      }
    }

    return $arrImages;
  }

  public function getProductCollectionFields(): array {
    $languages = $this->getAvailableLanguages();
    $return[] = [
      'name' => 'title',
      'type' => 'string'
    ];
    foreach($languages as $lang) {
      $return[] = [
        'name' => 'title_' . $lang,
        'type' => 'string'
      ];
    }
    $return[] = [
      'name' => 'description',
      'type' => 'string'
    ];
    foreach($languages as $lang) {
      $return[] = [
        'name' => 'description_' . $lang,
        'type' => 'string'
      ];
    }
    $return[] = [
      'name' => 'sku',
      'type' => 'string'
    ];
    $return[] = [
      'name' => 'url',
      'type' => 'string',
      'index' => false,
    ];
    $return[] = [
      'name' => 'image_url',
      'type' => 'string',
      'index' => false,
    ];
    $return[] = [
      'name' => 'price',
      'type' => 'float',
      'index' => true,
      'facet' => true,
      'range_index' => true,
    ];
    $return[] = [
      'name' => 'formatted_price',
      'type' => 'string',
      'index' => false,
    ];
    $return[] = [
      'name' => 'available',
      'type' => 'bool',
      'index' => true,
      'facet' => true,
    ];
    return $return;
  }

}