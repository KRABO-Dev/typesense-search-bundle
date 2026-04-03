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

namespace Krabo\TypesenseSearchBundle;

use Contao\CoreBundle\Search\Document;
use Contao\CoreBundle\Search\Indexer\IndexerException;
use Contao\CoreBundle\Search\Indexer\IndexerInterface;
use Contao\PageModel;

class Indexer implements  IndexerInterface {

  /**
   * Indexes a given document.
   *
   * @throws IndexerException If indexing did not work
   */
  public function index(Document $document): void
  {
    $jsonLdScriptsData =  $document->extractJsonLdScripts('https://schema.org', 'Product');
    if ($jsonLdScriptsData) {
      return; // Do not index product pages
    }

    $jsonLdScriptsData =  $document->extractJsonLdScripts('https://schema.contao.org/', 'Page');
    if (!$jsonLdScriptsData) {
      return;
    }

    $typesense = Typesense::getInstance();
    if (!$typesense->isEnabled()) {
      return;
    }
    foreach($jsonLdScriptsData as $data) {
      if (empty($data['pageId'])) {
        continue;
      }
      $objPage = PageModel::findByPk($data['pageId']);
      while($objPage->pid) {
        $objPage = PageModel::findByPk($objPage->pid);
      }
      $collection = 'pages_' . $objPage->id;
      $fields = [
        [
          'name' => 'title',
          'type' => 'string',
          'index' => true,
        ],
        [
          'name' => 'body',
          'type' => 'string',
          'index' => true,
        ],
        [
          'name' => 'url',
          'type' => 'string',
          'index' => false,
        ],
        [
          'name' => 'image_url',
          'type' => 'string',
          'index' => false,
        ],
      ];
      if (!$typesense->doesCollectionExist($collection)) {
        $typesense->createCollection($collection, $fields);
      } else {
        $typesense->updateCollection($collection, $fields);
      }


      try {
        $title = $document->getContentCrawler()->filterXPath('//head/title')->first()->text();
      } catch (\Exception $e) {
        $title = 'undefined';
      }
      $params = [
        'url' => (string)$document->getUri(),
        'title' => $title,
        'body' => $document->getBody(),
        'image_url' => '',
      ];
      $typesense->indexDocument($params, $document->getUri(), $collection);
    }
  }

  /**
   * Deletes a given document.
   *
   * @throws IndexerException If deleting did not work
   */
  public function delete(Document $document): void
  {
    $typesense = Typesense::getInstance();
    $jsonLdScriptsData =  $document->extractJsonLdScripts('https://schema.contao.org/', 'Page');
    if ($jsonLdScriptsData) {
      foreach($jsonLdScriptsData as $data) {
        if (empty($data['pageId'])) {
          continue;
        }
        $objPage = PageModel::findByPk($data['pageId']);
        while($objPage->pid) {
          $objPage = PageModel::findByPk($objPage->pid);
        }
        $collection = 'pages_' . $objPage->id;
        if ($typesense->doesCollectionExist($collection)) {
          $typesense->removeDocumentFromIndex($document->getUri(), $collection);
        }
      }
    }
  }

  /**
   * Clears the search index.
   *
   * @throws IndexerException If clearing did not work
   */
  public function clear(): void
  {
    $typesense = Typesense::getInstance();
    $objRootPages = PageModel::findBy('type', 'root');
    foreach ($objRootPages as $objRootPage) {
      $collection = 'pages_' . $objRootPage->id;
      if ($typesense->doesCollectionExist($collection)) {
        $typesense->deleteCollection($collection);
      }
    }
  }
}