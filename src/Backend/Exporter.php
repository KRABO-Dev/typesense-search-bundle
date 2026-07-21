<?php
/**
 * @author Jaap Jansma <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */

namespace Krabo\TypesenseSearchBundle\Backend;

use Contao\Config;
use Contao\Database;
use Contao\DataContainer;
use Contao\Date as ContaoDate;
use Contao\System;
use Date;
use DC_Table;
use Dflydev\DotAccessData\Data;
use Doctrine\DBAL\Exception\DriverException;
use StringUtil;

class Exporter {

  protected $procedure = [];
  protected $values = [];
  protected $firstOrderBy;
  protected $orderBy;

  protected $strTable = 'tl_typesense_analytics';
  protected $Database;

  public function export(DC_Table $objDc) {
    $this->Database = Database::getInstance();
    $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');
		$session = $objSessionBag->all();
    $filter = 'tl_typesense_analytics';
    $session['filter'][$filter]['limit'] = '0,30';

    // Custom filter
		if (!empty($GLOBALS['TL_DCA']['tl_typesense_analytics']['list']['sorting']['filter']) && \is_array($GLOBALS['TL_DCA']['tl_typesense_analytics']['list']['sorting']['filter']))
		{
			foreach ($GLOBALS['TL_DCA']['tl_typesense_analytics']['list']['sorting']['filter'] as $filter)
			{
				if (\is_string($filter))
				{
					$this->procedure[] = $filter;
				}
				else
				{
					$this->procedure[] = $filter[0];
					$this->values[] = $filter[1];
				}
			}
		}

    $intFilterPanel = 0;
    $arrPanes = StringUtil::trimsplit(';', $GLOBALS['TL_DCA']['tl_typesense_analytics']['list']['sorting']['panelLayout'] ?? '');
    foreach ($arrPanes as $strPanel)
		{
			$arrSubPanels = StringUtil::trimsplit(',', $strPanel);

			foreach ($arrSubPanels as $strSubPanel)
			{
				switch ($strSubPanel)
				{
					case 'search':
						$this->searchMenu();
						break;

					case 'sort':
						$this->sortMenu();
						break;

					case 'filter':
						// Multiple filter subpanels can be defined to split the fields across panels
						$this->filterMenu(++$intFilterPanel);
						break;
				}
			}
    }


    $query = "SELECT * FROM `tl_typesense_analytics`";
    if (!empty($this->procedure))
    {
      $query .= " WHERE " . implode(' AND ', $this->procedure);
    }
    if ($this->orderBy) {
      $query .= "ORDER BY " . implode(", ", $this->orderBy);
    }

    $db = Database::getInstance();
    $result = $db->prepare($query)->execute($this->values);
    
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename=export.csv");
    header("Pragma: no-cache");
    header("Expires: 0");
    $isFirstRow = true;
    while($row = $result->fetchAssoc()) {
      if ($isFirstRow) {
        $isFirstRow = false;
        echo $this->csvstr(array_keys($row));
        echo "\r\n";
      }
      echo $this->csvstr($row);
      echo "\r\n";
    } 
    exit();
  }

  protected function csvstr(array $fields) : string
  {
      $f = fopen('php://memory', 'r+');
      if (fputcsv($f, $fields) === false) {
          return false;
      }
      rewind($f);
      $csv_line = stream_get_contents($f);
      return rtrim($csv_line);
  }

  /**
	 * Generate the filter panel and return it as HTML string
	 *
	 * @param integer $intFilterPanel
	 *
	 * @return string
	 */
	protected function filterMenu($intFilterPanel)
	{
		/** @var AttributeBagInterface $objSessionBag */
		$objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

		$sortingFields = array();
		$session = $objSessionBag->all();
		$filter = $this->strTable;

		// Get the sorting fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if (($v['filter'] ?? null) == $intFilterPanel)
			{
				$sortingFields[] = $k;
			}
		}

		// Return if there are no sorting fields
		if (empty($sortingFields))
		{
			return;
		}



    foreach ($sortingFields as $field)
    {
      $what = Database::quoteIdentifier($field);

      if (isset($session['filter'][$filter][$field]))
      {
        // Sort by day
        if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, array(DataContainer::SORT_DAY_ASC, DataContainer::SORT_DAY_DESC)))
        {
          if (!$session['filter'][$filter][$field])
          {
            $this->procedure[] = $what . "=''";
          }
          else
          {
            $objDate = new ContaoDate($session['filter'][$filter][$field]);
            $this->procedure[] = $what . ' BETWEEN ? AND ?';
            $this->values[] = $objDate->dayBegin;
            $this->values[] = $objDate->dayEnd;
          }
        }

        // Sort by month
        elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, array(DataContainer::SORT_MONTH_ASC, DataContainer::SORT_MONTH_DESC)))
        {
          if (!$session['filter'][$filter][$field])
          {
            $this->procedure[] = $what . "=''";
          }
          else
          {
            $objDate = new Date($session['filter'][$filter][$field]);
            $this->procedure[] = $what . ' BETWEEN ? AND ?';
            $this->values[] = $objDate->monthBegin;
            $this->values[] = $objDate->monthEnd;
          }
        }

        // Sort by year
        elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, array(DataContainer::SORT_YEAR_ASC, DataContainer::SORT_YEAR_DESC)))
        {
          if (!$session['filter'][$filter][$field])
          {
            $this->procedure[] = $what . "=''";
          }
          else
          {
            $objDate = new Date($session['filter'][$filter][$field]);
            $this->procedure[] = $what . ' BETWEEN ? AND ?';
            $this->values[] = $objDate->yearBegin;
            $this->values[] = $objDate->yearEnd;
          }
        }

        // Manual filter
        elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null)
        {
          // CSV lists (see #2890)
          if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['csv']))
          {
            $this->procedure[] = $this->Database->findInSet('?', $field, true);
            $this->values[] = $session['filter'][$filter][$field] ?? null;
          }
          else
          {
            $this->procedure[] = $what . ' LIKE ?';
            $this->values[] = '%"' . $session['filter'][$filter][$field] . '"%';
          }
        }

        // Other sort algorithm
        else
        {
          $this->procedure[] = $what . '=?';
          $this->values[] = $session['filter'][$filter][$field] ?? null;
        }
      }
    }
		

		// Add sorting options
		foreach ($sortingFields as $cnt=>$field)
		{
			$arrValues = array();
			$arrProcedure = array();

			// Check for a static filter (see #4719)
			if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'] ?? null))
			{
				foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'] as $fltr)
				{
					if (\is_string($fltr))
					{
						$arrProcedure[] = $fltr;
					}
					else
					{
						$arrProcedure[] = $fltr[0];
						$arrValues[] = $fltr[1];
					}
				}
			}

			$what = Database::quoteIdentifier($field);

			// Optimize the SQL query (see #8485)
			if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag']))
			{
				// Sort by day
				if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(DataContainer::SORT_DAY_ASC, DataContainer::SORT_DAY_DESC)))
				{
					$what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%Y-%m-%d'))), '') AS $what";
				}

				// Sort by month
				elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(DataContainer::SORT_MONTH_ASC, DataContainer::SORT_MONTH_DESC)))
				{
					$what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%Y-%m-01'))), '') AS $what";
				}

				// Sort by year
				elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(DataContainer::SORT_YEAR_ASC, DataContainer::SORT_YEAR_DESC)))
				{
					$what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%Y-01-01'))), '') AS $what";
				}
			}
		}
	}

  protected function sortMenu()
	{
		if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) != DataContainer::MODE_SORTABLE && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) != DataContainer::MODE_PARENT)
		{
			return '';
		}

		$sortingFields = array();

		// Get sorting fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if ($v['sorting'] ?? null)
			{
				$sortingFields[] = $k;
			}
		}

		// Return if there are no sorting fields
		if (empty($sortingFields))
		{
			return '';
		}

		/** @var AttributeBagInterface $objSessionBag */
		$objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

		$session = $objSessionBag->all();
		$orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? array('id');
    $overwrite = preg_quote(preg_replace('/\s+.*$/', '', $session['sorting'][$this->strTable]), '/');
    $orderBy = array_diff($orderBy, preg_grep('/^' . $overwrite . '/i', $orderBy));

    array_unshift($orderBy, $session['sorting'][$this->strTable]);

    $this->firstOrderBy = $overwrite;
    $this->orderBy = $orderBy;
	}

  protected function searchMenu()
	{
		$searchFields = array('id');

		/** @var AttributeBagInterface $objSessionBag */
		$objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

		$session = $objSessionBag->all();

		// Get search fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if ($v['search'] ?? null)
			{
				$searchFields[] = $k;
			}
		}

		// Return if there are no search fields
		if (empty($searchFields))
		{
			return '';
		}


    $searchValue = $session['search'][$this->strTable]['value'];
    $fld = $session['search'][$this->strTable]['field'] ?? null;

    try
    {
      $this->Database->prepare("SELECT '' REGEXP ?")->execute($searchValue);
    }
    catch (DriverException $exception)
    {
      // Quote search string if it is not a valid regular expression
      $searchValue = preg_quote($searchValue, null);
    }

    $strReplacePrefix = '';
    $strReplaceSuffix = '';

    // Decode HTML entities to make them searchable
    if (empty($GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['eval']['decodeEntities']))
    {
      $arrReplace = array(
        '&#35;' => '#',
        '&#60;' => '<',
        '&#62;' => '>',
        '&lt;' => '<',
        '&gt;' => '>',
        '&#40;' => '(',
        '&#41;' => ')',
        '&#92;' => '\\\\',
        '&#61;' => '=',
        '&amp;' => '&',
      );

      $strReplacePrefix = str_repeat('REPLACE(', \count($arrReplace));

      foreach ($arrReplace as $strSource => $strTarget)
      {
        $strReplaceSuffix .= ", '$strSource', '$strTarget')";
      }
    }
    if (!strlen($searchValue)) {
      return;
    }

    $strPattern = "$strReplacePrefix CAST(%s AS CHAR) $strReplaceSuffix REGEXP ?";

    if (substr(Config::get('dbCollation'), -3) == '_ci')
    {
      $strPattern = "$strReplacePrefix LOWER(CAST(%s AS CHAR)) $strReplaceSuffix REGEXP LOWER(?)";
    }

    if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['foreignKey']))
    {
      list($t, $f) = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['foreignKey'], 2);
      $this->procedure[] = "(" . sprintf($strPattern, Database::quoteIdentifier($fld)) . " OR " . sprintf($strPattern, "(SELECT " . Database::quoteIdentifier($f) . " FROM $t WHERE $t.id=" . $this->strTable . "." . Database::quoteIdentifier($fld) . ")") . ")";
      $this->values[] = $searchValue;
    }
    else
    {
      $this->procedure[] = sprintf($strPattern, Database::quoteIdentifier($fld));
    }

    $this->values[] = $searchValue;
	}

}