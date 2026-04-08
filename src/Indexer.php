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
use Contao\StringUtil;

class Indexer implements  IndexerInterface {

  /**
   * Indexes a given document.
   *
   * @throws IndexerException If indexing did not work
   */
  public function index(Document $document): void
  {
    $pageId = null;
    $rootPageId = null;
    $jsonLdScriptsData =  $document->extractJsonLdScripts('https://schema.contao.org/', 'Page');
    $typesense = Typesense::getInstance();
    if (!$typesense->isEnabled()) {
      return;
    }

    try {
      $title = $document->getContentCrawler()->filterXPath('//head/title')->first()->text();
      $title = explode(" - ", $title);
      if (count($title)>1) {
        array_pop($title);
      }
      $title = implode(" - ", $title);
    } catch (\Exception $e) {
      $title = 'undefined';
    }

    foreach($jsonLdScriptsData as $data) {
      if (!empty($data['pageId'])) {
        $pageId = $data['pageId'];
        $objRootPage = PageModel::findByPk($data['pageId']);
        while($objRootPage->pid) {
          $objRootPage = PageModel::findByPk($objRootPage->pid);
        }
        $rootPageId = $objRootPage->id;
        break;
      }
    }

    $rawJsonLds = $document->extractJsonLdScripts();
    $jsonLds = [];
    foreach ($rawJsonLds as $rawJsonLd) {
      if (!empty($rawJsonLd['@type'])) {
        $jsonLds[] = $rawJsonLd['@type'];
      } elseif (!empty($rawJsonLd['@context'])) {
        $jsonLds[] = $rawJsonLd['@context'];
      }
    }

    $collection = 'pages_' . $rootPageId ?? 0;
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
        'name' => 'description',
        'type' => 'string',
        'index' => true,
        'optional' => true,
      ],
      [
        'name' => 'full_text',
        'type' => 'string',
        'index' => true,
        'optional' => true,
      ],
      [
        'name' => 'url',
        'type' => 'string',
        'index' => false,
      ],
      [
        'name' => 'jsonLd',
        'type' => 'string[]',
        'index' => true,
        'facet' => true,
        'optional' => true,
      ]
    ];
    if (!$typesense->doesCollectionExist($collection)) {
      $typesense->createCollection($collection, $fields, 'page');
    } else {
      $typesense->updateCollection($collection, $fields, 'page');
    }

    $arrData = $this->extractContents($document->getBody(), $title);
    $arrData['pageId'] = $pageId;
    $arrData['rootPageId'] = $rootPageId;
    $arrData['jsonLds'] = $jsonLds;
    $arrData['document'] = $document;
    $params = [
      'url' => (string)$document->getUri(),
      'title' => $title,
      'body' => $arrData['body'],
      'description' => $arrData['description'],
      'full_text' => $arrData['text'],
      'jsonLd' => $jsonLds,
    ];
    $typesense->indexDocument($params, $document->getUri(), $collection, 'page', $arrData);
  }

  private function extractContents(string $content, string $title): array {
    $strContent = str_replace(array("\n", "\r", "\t", '&#160;', '&nbsp;', '&shy;'), array(' ', ' ', ' ', ' ', ' ', ''), $content);

    // Strip script tags
    while (($intStart = strpos($strContent, '<script')) !== false)
    {
      if (($intEnd = strpos($strContent, '</script>', $intStart)) !== false)
      {
        $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
      }
      else
      {
        break; // see #5119
      }
    }

    // Strip style tags
    while (($intStart = strpos($strContent, '<style')) !== false)
    {
      if (($intEnd = strpos($strContent, '</style>', $intStart)) !== false)
      {
        $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 8);
      }
      else
      {
        break; // see #5119
      }
    }

    // Strip non-indexable areas
    while (($intStart = strpos($strContent, '<!-- indexer::stop -->')) !== false)
    {
      if (($intEnd = strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false)
      {
        $intCurrent = $intStart;

        // Handle nested tags
        while (($intNested = strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd)
        {
          if (($intNewEnd = strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false)
          {
            $intEnd = $intNewEnd;
            $intCurrent = $intNested;
          }
          else
          {
            break; // see #5119
          }
        }

        $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 26);
      }
      else
      {
        break; // see #5119
      }
    }

    $arrMatches = array();
    preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE);
    $intOffset = \strlen($arrMatches[0][0]) + $arrMatches[0][1];

    // Split page in head and body section
    $strHead = substr($strContent, 0, $intOffset);
    $strBody = substr($strContent, $intOffset);

    unset($strContent);

    $tags = array();
    // Get the description
    $arrData['description'] = '';
    if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
    {
      $arrData['description'] = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($tags[1])));
    }

    // Add a whitespace character before line-breaks and between consecutive tags (see #5363)
    $strBody = str_ireplace(array('<br', '><'), array(' <br', '> <'), $strBody);
    $strBody = strip_tags($strBody);
    $strBody = trim($strBody);
    $strBody = StringUtil::decodeEntities($strBody);
    $arrData['body'] = $strBody;

    // Put everything together
    $arrData['text'] = $strBody . ' ' . $arrData['description'] . "\n" . $title;
    $arrData['text'] = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($arrData['text'])));
    return $arrData;
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