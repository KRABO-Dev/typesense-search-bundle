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

namespace Krabo\TypesenseSearchBundle\Cron;

use Contao\CoreBundle\Framework\ContaoFramework;
use Isotope\Model\Product;
use Krabo\TypesenseSearchBundle\Helper\IsotopeProductHelper;
use Krabo\TypesenseSearchBundle\Typesense;
use Psr\Log\LoggerInterface;

class IndexIsoProductsCron {

  private ContaoFramework $framework;

  /**
   * @param ContaoFramework $contaoFramework
   * @param LoggerInterface|null $logger
   */
  public function __construct(ContaoFramework $contaoFramework)
  {
    $this->framework = $contaoFramework;
  }

  public function __invoke(): void {
    $this->framework->initialize();
    $typesense = Typesense::getInstance();
    if (!$typesense->isEnabled()) {
      return;
    }
    $helper = new IsotopeProductHelper();
    $helper->removeProductIndexes();
    $helper->createProductIndexes();
    $objProducts = Product::findBy([], []);
    foreach ($objProducts as $objProduct) {
      $helper->indexProductDocument($objProduct);
    }
  }

}