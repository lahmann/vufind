<?php
/**
 * ILS Driver for VuFind to get information from PAIA
 *
 * PHP version 5
 *
 * Copyright (C) Oliver Goldschmidt, Magda Roos, Till Kinstler 2013, 2014.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @author   Magdalena Roos <roos@gbv.de>
 * @author   Till Kinstler <kinstler@gbv.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */

namespace VuFind\ILS\Driver;
use DOMDocument, VuFind\Exception\ILS as ILSException;

/**
 * Extends generic PAIA driver with methods to get additional data from PICA LBS systems
 *
 * Holding information is obtained by DAIA, so it's not necessary to implement those
 * functions here; we just need to extend the DAIA driver.
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Oliver Goldschmidt <o.goldschmidt@tuhh.de>
 * @author   Magdalena Roos <roos@gbv.de>
 * @author   Till Kinstler <kinstler@gbv.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */
class PAIAPica extends \VuFind\ILS\Driver\PAIA
{
    private $_username;
    private $_password;
    private $_ldapConfigurationParameter;

    protected $baseURL;
    protected $paiaURL;
    protected $catalogURL;
    protected $loanURL;
    protected $opacfno;

    /**
     * Constructor
     *
     * @access public
     */
    public function init()
    {
        parent::init();

        if (!isset($this->config['PICACatalog']['URL'])) {
            throw new ILSException('PICACatalog/URL configuration needs to be set.');
        }

        /*if (!(isset($this->config['PAIA']['URL']) || isset($this->config['Catalog']['loanURL']))) {
            throw new ILSException('Catalog/loanURL or PAIA/URL configuration needs to be set.');
        }*/

        $this->catalogURL = $this->config['PICACatalog']['URL'];
        $this->loanURL = $this->config['PICACatalog']['loanURL'];
        $this->opacfno = $this->config['PICACatalog']['opacfno'];

        //$this->paiaURL = $this->config['PAIA']['URL'];

    }

    /**
     * Fallback, if PAIA not available: return URL to PICA LBS Loan module
     *
     * @param $id
     * @param $details
     * @return string
     */
    protected function getILSHoldLink($id, $details) {
        if (isset($details['item_id'])) {
            $epn = $details['item_id'];
            if (preg_match("/epn:([X\d]{9})/", $epn, $match)) {
                $epn = $match[1];
            }
            $hold = $this->loanURL."?EPN=". $this->prfz($epn) . "&MTR=mon"
                ."&BES=".$this->opacfno."&LOGIN=ANONYMOUS";
            /*    $hold = $this->loanURL."?MTR=mon&LOGIN=ANONYMOUS"
                            ."&BES=".$this->opacfno
                            ."&EPN=".$epn; */
            return $hold;
        }
        return $this->opcloan."?MTR=mon" ."&BES=".$this->opacfno
        ."&EPN=".$id;
    }

    /**
     * Get Funds
     *
     * Return a list of funds which may be used to limit the getNewItems list.
     *
     * TODO: implement it for PICA
     *
     * @return array An associative array with key = fund ID, value = fund name.
     * @access public
     */
    public function getFunds()
    {
        return null;
    }

    /**
     * Support method to retrieve needed ItemId in case PAIA-response does not
     * contain it
     *
     * @param string $id itemId
     *
     * @return string $id
     * @access private
     */
    protected function getAlternativeItemId($id)
    {
        return $this->_getPpnByBarcode($id);
    }

    /**
     * gets a PPN by its barcode from PICA OPC4 
     *
     * @param string $barcode Barcode to use for lookup
     *
     * @return string         PPN
     * @access private
     */
    private function _getPpnByBarcode($barcode)
    {
        $barcode = str_replace("/"," ",$barcode);
        if (preg_match("/bar:(.*)$/", $barcode, $match)) {
            $barcode = $match[1];
        } else {
            return false;
        }
        $searchUrl = $this->catalogURL .
            "XML=1.0/CMD?ACT=SRCHA&IKT=1016&SRT=YOP&TRM=bar+$barcode";

        $doc = new DomDocument();
        $doc->load($searchUrl);
        // get Availability information from DAIA
        $itemlist = $doc->getElementsByTagName('SHORTTITLE');
        if (isset($itemlist->item(0)->attributes) && count($itemlist->item(0)->attributes) > 0) {
            $ppn = $itemlist->item(0)->attributes->getNamedItem('PPN')->nodeValue;
        } else {
            return false;
        }
        return $ppn;
    }

    /**
     * gets holdings of magazine and journal exemplars
     *
     * @param string $ppn PPN identifier
     *
     * @return array
     * @access public
     */
    public function getJournalHoldings($ppn)
    {
        $searchUrl = $this->catalogURL .
            "XML=1.0/SET=1/TTL=1/FAM?PPN=" . $ppn . "&SHRTST=100";
        $doc = new DomDocument();
        $doc->load($searchUrl);
        $itemlist = $doc->getElementsByTagName('SHORTTITLE');
        $ppn = array();
        for ($n = 0; $itemlist->item($n); $n++) {
            if (count($itemlist->item($n)->attributes) > 0) {
                $ppn[] = $itemlist->item($n)->attributes->getNamedItem('PPN')->nodeValue;
            }
        }
        return $ppn;
    }

    /**
     * Helper function to compute the modulo 11 based
     * ppn control number
     *
     * @param string $str input
     *
     * @return string
     */
    protected function prfz($str)
    {
        $x = 0; $y = 0; $w = 2;
        $stra = str_split($str);
        for ($i=strlen($str); $i>0; $i--) {
            $c = $stra[$i-1];
            $x = ord($c) - 48;
            $y += $x*$w;
            $w++;
        }
        $p = 11-$y%11;
        if ($p==11) {
            $p=0;
        }
        if ($p==10) {
            $ret = $str."X";
        } else {
            $ret = $str.$p;
        }
        return $ret;
    }

}
?>
