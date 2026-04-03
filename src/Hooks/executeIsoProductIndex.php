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

namespace Krabo\TypesenseSearchBundle\Hooks;

use Contao\Controller;
use Isotope\Model\Product;
use Krabo\TypesenseSearchBundle\Helper\IsotopeProductHelper;

class executeIsoProductIndex extends  Controller {
  public function ajaxHandler($strAction)
  {
    if($strAction=='typesense_iso_product_index')
    {
      $helper = new IsotopeProductHelper();
      $intLimit = 50;
      $intOffset = (int) \Input::post('offset');

      if ($intOffset == 0) {
        $helper->removeProductIndexes();
        $helper->createProductIndexes();
      }
      $languages = $helper->getAvailableLanguages();

      $objProducts = Product::findBy(array(), array(), array('limit'=> $intLimit, 'offset'=> $intOffset));
      $varOffset = null !== $objProducts ? ($intOffset+$intLimit) :  'finished';
      $strMessage = null !== $objProducts ? ($intOffset+$intLimit) . ' products scanned...' : 'Typesense refresh complete';
      if(null !== $objProducts)
      {
        while($objProducts->next())
        {
          $helper->indexProductDocument($objProducts->current(), $languages);
        }
      }

      $arrContent = array
      (
        'data' => array(

          'offset'	=> $varOffset,
          'message'	=> $strMessage,
        ),
        'token'   => REQUEST_TOKEN,
        'content' => ""
      );

      $objResponse = new \Haste\Http\Response\JsonResponse($arrContent);
      $objResponse->send();
    }
  }
}