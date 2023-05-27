<?php namespace rogoss\yafip;

require_once __DIR__ . "/ComponentChunk.php";

use rogoss\core\Utils;
use Exception;

class Page {

    public static function load($sPageName) {

        $sPathDocRoot = rtrim($_SERVER['DOCUMENT_ROOT'], "\\/") . "/";

        $sPagesPath     = $sPathDocRoot . rtrim(self::getEnvVar("DE_ROCCOGOSSMANN_PHP_FUNFRAMES_PAGESROOT")     , "\\/");
        $sComponentPath = $sPathDocRoot . rtrim(self::getEnvVar("DE_ROCCOGOSSMANN_PHP_FUNFRAMES_COMPONENTSROOT"), "\\/");

        $sPagePath     = $sPagesPath. "/" . rtrim($sPageName, "\\/");
        $sPageTemplate = $sPagePath . "/_template.ff.php";
        $sPageCompiled = $sPagePath . "/_page.ff.php";

        $oI = (file_exists($sPageCompiled) && file_exists($sPageTemplate))
            ? static::fromCompiled($sPageCompiled, $sPagePath, $sComponentPath)
            : static::createFromLayout($sPagePath, $sComponentPath)
                ->compileTemplate($sPageTemplate)
                ->compilePage($sPageCompiled)
        ;

        if(!$oI->fresh) {
            $bSpoiled=false;

            if($oI->oLayout->recompiled) 
                $bSpoiled = true;

            else foreach($oI->aLayouts as $oLayout) 
                if($oLayout->recompiled) {
                    $bSpoiled = true;
                    break;
                }
                
            if($bSpoiled) {
                $oI = static::createFromLayout($sPageName, $sComponentPath)
                    ->compileTemplate($sPageTemplate)
                    ->compilePage($sPageCompiled)
                ;
            }

        }

        $oI->sCompiledTemplateFile = $sPageTemplate;

        return $oI;
    }


    /**
     * Recreate a Page-Instances that had been created in the past 
     *
     * @param string $sFile the file, that can be included
     * @param string $sPath the path to the folder, that contains the mainlayout-html  (in case a recompilation is needed)
     * @param string $sComponentsPath the path that contains all the components to be used by any layout  (in case a recompilation is needed)
     *
     * @return static
     */
    protected static function fromCompiled($sFile, $sPagePath, $sComponentPath) {
        $meta = include $sFile;    

        try {

            if(!(   is_array($meta) 
                and is_array($meta['mainlayout'] ?? false) 
                and is_array($meta['sublayouts'] ?? false) 
            )) throw new Exception("missing array field");

            $oI = new static();

            $oI->oLayout = Layout::load($meta['mainlayout'][1] ?? "");

            foreach($meta['sublayouts'] as $sKey => $aSubLayout)
                $oI->aLayouts[$sKey] = Layout::load($aSubLayout[1]);

            if(!empty($meta['datafiles']) and is_array($meta['datafiles'])) {
                $aData = [];

                foreach($meta['datafiles'] as $sContext => $sDataFile) {
                    $aCompData = file_exists($sDataFile) 
                        ? include ($oI->aDataFiles[$sContext] = $sDataFile)
                        : []
                    ;

                    $aFlattened = Utils::flattenArray($aCompData, null, $sContext);

                    foreach($aFlattened as $sKey => &$mValue) 
                        if(is_callable($mValue)) 
                            $mValue = $mValue($sKey, $oI->aData[$sKey] ?? null);

                    Utils::mutateArrayRecursive($oI->aData, $aFlattened);

                }
            }
            return $oI;

        } 
        catch(Exception $ex) {
            unlink($sFile);
            return self::createFromLayout($sPagePath, $sComponentPath);
        }
    }

    /**
     * Create a new instance based on a given layout 
     *
     * @param string $sPath the path to the folder, that contains the mainlayout-html
     * @param string $sComponentsPath the path that contains all the components to be used by any layout
     *
     * @return Page 
     */
    protected static function createFromLayout($sPath, $sComponentsPath) {
        $oI = new static();
        $oI->oLayout = $oLayout = Layout::load($sPath);
        $oI->fresh = true;

        $aComponentTree = [];

        /** @var ComponentChunk[] */
        $aProcessList = [];

        /** @var string[] */
        $aProcessKeys = [];

        $aCaches = [];

        $aDataTokens = [];
        $aComponentTokens = [];

        $aDataFiles = [];

        $aData = file_exists($sPath . "/data.php") 
            ? include ($aDataFiles[""] = $sPath . "/data.php")
            : []
        ;

        if (!is_array($aData)) $aData = [];

        $aContextList = [];

        foreach ($oLayout->getTokens() as $sKey) {
            $aComponentTree[$sKey] = new ComponentChunk($sKey);
            $aProcessList[] = &$aComponentTree[$sKey];
            $aProcessKeys[] = $sKey;
            $aData[$sKey] = [];
            $aDataProcess[] = &$aData[$sKey];
            $aContextList[] = $sKey;
        }

        $iIndex = 0;
        while ($sKey = array_shift($aProcessKeys)) {

            if (file_exists($sComponentsPath . "/" . $sKey)) {
                $oLayout = $aCaches[$sKey] ?? Layout::load($sComponentsPath . "/" . $sKey);
                $aProcessList[$iIndex]->layout = $oLayout;

                $aCompData = file_exists($sComponentsPath . "/" . $sKey . "/data.php") 
                    ? include ($aDataFiles[$aContextList[$iIndex]] = $sComponentsPath . "/" . $sKey . "/data.php") 
                    : []
                ;

                if (!is_array($aCompData)) $aCompData = [];

                $aComponentTokens[] = &$aProcessList[$iIndex];

                // $aDataProcess[$iIndex] = &array_replace_recursive($aDataProcess[$iIndex], $aCompData);
                Utils::mutateArrayRecursive($aDataProcess[$iIndex], $aCompData);

                $aCaches[$sKey] = $oLayout;
                $aLayoutTokens = $oLayout->getTokens();
                if (count($aLayoutTokens)) {
                    foreach ($aLayoutTokens as $sTokenKey) {
                        $aProcessList[$iIndex]->components[$sTokenKey] = new ComponentChunk($sTokenKey, $oLayout);
                        $aProcessList[$iIndex]->components[$sTokenKey]->parent = $aProcessList[$iIndex];
                        $aProcessList[] = &$aProcessList[$iIndex]->components[$sTokenKey];
                        $aProcessKeys[] = $sTokenKey;
                        $aDataProcess[] = &$aDataProcess[$iIndex][$sTokenKey];
                        $aContextList[] = (empty($_ = $aContextList[$iIndex]) ? "" : $_) . "." . $sTokenKey;
                    }
                }
            } else {
                $aProcessList[$iIndex]->data = "";
                $aDataTokens[] = &$aProcessList[$iIndex];
            }

            $iIndex++;
        }

        $oI->aComponentTree = $aComponentTree;
        $oI->aLayouts = $aCaches;
        $oI->aDataFiles = $aDataFiles;

        $oI->aData = Utils::flattenArray($aData);

        return $oI;
    }

    /** @var Layout */
    protected $oLayout = null;

    /** @var Layout[] a list of component instances */
    private $aLayouts = [];

    /** @var string[] */
    private $aDataFiles = [];

    /** @var bool defines, if the page has been compiled this cycle */
    private $fresh = false;

    /** @var Array<string, mixed> */
    private $aData = [];

    /** @var array the list of components as they are arranged by the layout */
    private $aComponentTree = [];

    /** @var string the name of the compiled template file, to be included during render */
    private $sCompiledTemplateFile = "";
    

    protected function compilePage($sOutputFile) {

        $_data = [
              'mainlayout' => [ $this->oLayout->hash, dirname($this->oLayout->filepath) ]
            , 'sublayouts' => []
            , 'datafiles' => $this->aDataFiles 
        ];

        foreach($this->aLayouts as $sKey => $oLayout) 
            $_data['sublayouts'][$sKey] = [ $oLayout->hash, dirname($oLayout->filepath) ];

        $hF = fopen($sOutputFile, "w");
        if(!$hF) throw PageException::noFile($sOutputFile);

        fwrite($hF, "<?php\n\nreturn ");
        fwrite($hF, var_export($_data, true));
        fwrite($hF, ";");

        fclose($hF);

        return $this;

    }

    /**
     * Prerenders the entire pages (with exception to its data-components)
     *
     * @param string $sOutputFile the file, the content will be rendered to 
     *
     * @throws PageException - if no file is found
     * @throws Exception - if eanything goes wrong, while parsing the layout-tree
     *
     * @return static - returns $this, because builder-pattern
     */
    protected function compileTemplate($sOutputFile) {
        function recurse(Layout $oLayout, $hCacheFile, $me, &$aBranchRoot, $sSlot = "", $sPrefix = "") {
            foreach ($oLayout->chunks() as $aChunk) {
                switch ($aChunk['type']) {
                    case "raw":
                        fwrite($hCacheFile, $aChunk['text']);
                        break;

                    case "slot":
                        if (isset($aBranchRoot[$aChunk['slot']])) {
                            /** @var ComponentChunk */
                            $oChunk = $aBranchRoot[$aChunk['slot']];
                            if (empty($oChunk->layout)) fwrite($hCacheFile, "<?php \$this->renderData('{$oChunk->label}'); ?>");
                            else                        recurse($oChunk->layout, $hCacheFile, $me, $oChunk->components, $aChunk['slot'], $sPrefix . "." . $sSlot);
                        }
                        break;
                }
            }
        }

        $hFile = fopen($sOutputFile . ".tmp", "w");
        if ($hFile) {
            try {
                recurse($this->oLayout, $hFile, $this, $this->aComponentTree);
            } catch (Exception $ex) {
                fclose($hFile);
                $hFile = null;
                unlink($sOutputFile . ".tmp");
                throw $ex;
            } finally {
                if ($hFile) {
                    fclose($hFile);
                    if (file_exists($sOutputFile)) unlink($sOutputFile);
                    rename($sOutputFile . ".tmp", $sOutputFile);
                    $hFile = null;
                }
            }
        } else throw PageException::noFile($sOutputFile);

        return $this;
    }

    /**
     * Used by the include inside $this->render()
     *
     * @param string $sToken the data-token
     *
     * @return void 
     */
    private function renderData($sToken) {

        $mToken = $this->aData[$sToken] ?? "";

        if(is_callable($mToken)) $mToken();
        else echo $mToken;

    }

    public function render() {
        echo "<!DOCTYPE html>";
        include $this->sCompiledTemplateFile;
    }

    public function getData($sPath) {
        return $this->aData[$sPath] ?? false;
    }

    private static function getEnvVar($sVarName) {
        $sVar = getenv($sVarName) ?? null;
        if(empty($sVar)) throw PageException::noEnvVar($sVarName);
        return $sVar;
    }

}

class PageException extends \Exception
{
    const NO_FILE      = 1;
    const NO_EVNVAR    = 2;
    const MISSING_PAGE = 3;

    public static function noFile($sFileName) {
        return new static("missing file of failed to create '$sFileName'", static::NO_FILE);
    }

    public static function noEnvVar($sVarName) {
        return new static("Missing Environment-Varable '$sVarName' please define it first", static::NO_EVNVAR);
    }

    public static function missingPage($sFullPagePath) {
        return new static("The there is no page at the path '$sFullPagePath'", static::MISSING_PAGE); 
    }
}
