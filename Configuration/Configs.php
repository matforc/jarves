<?php
/**
 * This file is part of Jarves.
 *
 * (c) Marc J. Schmidt <marc@marcjschmidt.de>
 *
 *     J.A.R.V.E.S - Just A Rather Very Easy [content management] System.
 *
 *     http://jarves.io
 *
 * To get the full copyright and license information, please view the
 * LICENSE file, that was distributed with this source code.
 */

namespace Jarves\Configuration;

use Jarves\Jarves;
use Jarves\Exceptions\BundleNotFoundException;
use Jarves\Objects;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class Configs implements \IteratorAggregate
{
    /**
     * @var Bundle[]
     */
    private $configElements = array();

    /**
     * @var Jarves
     */
    protected $core;

    protected $triggeredReboot = [];

    protected $needRebootBy = [];

    /**
     * @param Jarves $core
     * @param string[] $bundles
     */
    public function __construct(Jarves $core, array $bundles = null)
    {
        $this->setCore($core);
        if ($bundles) {
            $this->loadBundles($bundles);
        }
    }

    /**
     * @param array $bundles
     */
    public function loadBundles(array $bundles)
    {
        foreach ($bundles as $bundleName) {
            $bundle = $this->getJarves()->getBundle($bundleName);
            $configs = $this->getXmlConfigsForBundle($bundle);
            $this->configElements = array_merge($this->configElements, $configs);
        }

        $this->configElements = $this->parseConfig($this->configElements);
    }

    /**
     * Recalls `bootRunTime` on each field when this is call inside one bootRunTime.
     * Needed to keep all bootRunTime informed when someone added or changed a field.
     *
     * @param string $source
     */
    public function addReboot($source)
    {
        $this->needRebootBy[] = $source ?: 'n/a';
    }

    public function resetReboot()
    {
        $this->needRebootBy = [];
    }

    public function needsReboot()
    {
        return !!$this->needRebootBy;
    }

    /**
     * @param \Jarves\Jarves $core
     */
    public function setCore($core)
    {
        $this->core = $core;
    }

    /**
     * @return \Jarves\Jarves
     */
    public function getJarves()
    {
        return $this->core;
    }

    /**
     * Returns a md5 hash of all jarves config files (Resources/config/jarves.*.xml).
     *
     * @param string $bundleName
     * @return string
     */
    public function getConfigHash($bundleName)
    {
        $hash = [];
        $bundle = $this->getJarves()->getBundle($bundleName);
        foreach ($this->getConfigFiles($bundle) as $file) {
            $hash[] = filemtime($file);
        }

        return md5(implode('.', $hash));
    }

    /**
     * @param BundleInterface $bundle
     * @return string[]
     */
    public function getConfigFiles(BundleInterface $bundle)
    {
        $configDir = $bundle->getPath() . '/Resources/config/';
        $baseFile = $configDir . 'jarves.xml';

        $files = [];
        if (file_exists($baseFile)) {
            $files = [$configDir . 'jarves.xml'];
        }

        if (file_exists($configDir)) {
            $files = array_merge($files, glob($configDir . 'jarves.*.xml'));
        }

        return $files;
    }

    /**
     * Returns a array with following structure:
     *
     *   array[$bundleName][$priority][$file] = $bundle
     *
     * @param BundleInterface $bundle
     * @return array
     */
    public function getXmlConfigsForBundle(BundleInterface $bundle)
    {
        $configs = array();

        foreach ($this->getConfigFiles($bundle) as $file) {
            if (file_exists($file) && file_get_contents($file)) {
                if (function_exists('ppm_register_file')) {
                    ppm_register_file($file);
                }
                $doc = new \DOMDocument();
                $doc->load($file);

                $bundles = $doc->getElementsByTagName('bundle');
                $bundleToImport = $bundle;
                foreach ($bundles as $bundleXml) {
                    if ($bundleXml->attributes->getNamedItem('name')) {
                        $bundleName = $bundleXml->attributes->getNamedItem('name')->nodeValue;
                        if (!$bundleToImport = $this->getJarves()->getBundle($bundleName)) {
                            continue;
                        }
                    }
                    $priority = 0;
                    if ($bundleXml->attributes->getNamedItem('priority')) {
                        $priority = (int)$bundleXml->attributes->getNamedItem('priority')->nodeValue;
                    }

                    $configs[get_class($bundleToImport)][$priority][$file] = $bundleXml;
                }
            }
        }

        return $configs;
    }

    /**
     * @return bool
     */
    public function boot()
    {
        $i = 0;
        $rebootSources = [];
        while ($i == 0 || $this->needsReboot()) {
            $this->resetReboot();
            foreach ($this->configElements as $config) {
                $config->boot($this);
            }
            if ($this->needsReboot()) {
                $rebootSources = array_merge($rebootSources, $this->needRebootBy);
            }
            $i++;
            if ($i > 100) {
                throw new \RuntimeException(sprintf(
                    'Can not boot bundle configuration, there is a infinite loop. Reboots triggered by: ',
                    json_encode($rebootSources, JSON_PRETTY_PRINT)
                ));
            }
        }
    }

    /**
     * @return array
     */
    public function getTriggeredReboots()
    {
        return $this->triggeredReboot;
    }

    /**
     * $configs = $configs[$bundleName][$priority][] = $bundleDomElement;
     *
     * Parses and merges(imports) bundle configurations.
     *
     * @param array $configs
     *
     * @return \Jarves\Configuration\Bundle[]
     */
    public function parseConfig(array $configs)
    {
        $bundleConfigs = array();
        foreach ($configs as $bundleName => $priorities) {
            ksort($priorities); //sort by priority

            foreach ($priorities as $configs) {
                foreach ($configs as $file => $bundleElement) {

                    $bundle = $this->getJarves()->getBundle($bundleName);
                    $indexName = $this->normalizeBundleName($bundle->getName());
                    if (!isset($bundleConfigs[$indexName])) {
                        $bundleConfigs[$indexName] = new Bundle($bundle, $this->getJarves());
                    }

                    try {
                        $bundleConfigs[$indexName]->import($bundleElement, $file);
                    } catch (\Exception $e) {
                        throw new \RuntimeException(sprintf('Can not import configuration %s of bundle %s', $file, $bundleName), 0, $e);
                    }
                }
            }

        }

        return $bundleConfigs;
    }

    /**
     * @param string $bundleName
     *
     * @return \Jarves\Configuration\Bundle
     */
    public function getConfig($bundleName)
    {
        $bundleName = $this->normalizeBundleName($bundleName);

        if (!isset($this->configElements[$bundleName]) && isset($this->configElements[$bundleName . 'bundle'])) {
            $bundleName .= 'bundle';
        }

        return isset($this->configElements[$bundleName]) ? $this->configElements[$bundleName] : null;
    }

    /**
     * @param string $bundleName short version, long bundle name of full php class name
     * @return string short lowercased bundle name
     */
    public function normalizeBundleName($bundleName)
    {
        $bundleName = preg_replace('/bundle$/', '', strtolower($bundleName));
        if (false !== $pos = strrpos($bundleName, '\\')) {
            //it's a php class name
            $bundleName = substr($bundleName, $pos + 1);
        }
        return $bundleName;
    }

    /**
     * @param Bundle $bundle
     */
    public function addConfig(Bundle $bundle)
    {
        $this->configElements[$this->normalizeBundleName($bundle->getBundleName())] = $bundle;
    }

    /**
     * @param string $objectKey
     * @return Object
     *
     * @throws BundleNotFoundException
     */
    public function getObject($objectKey)
    {
        $objectKey = Objects::normalizeObjectKey($objectKey);

        if (false === strpos($objectKey, '/')) {
            return null;
        }

        list($bundleName, $objectName) = explode('/', $objectKey);

        if ('bundle' !== substr($bundleName, -6)) {
            $bundleName .= 'bundle';
        }

        if (!$config = $this->getConfig($bundleName)) {
            throw new BundleNotFoundException(sprintf('Bundle `%s` not found. [%s]', $bundleName, $objectKey));
        }

        return $config->getObject($objectName);
    }

    /**
     * @return Object[]
     */
    public function getObjects()
    {
        $result = [];

        foreach ($this->getConfigs() as $config) {
            if ($items = $config->getObjects()) {
                $result = array_merge($result, $items);
            }
        }

        return $result;
    }

    /**
     * @param string $themeId
     * @return Theme|null
     */
    public function getTheme($themeId)
    {
        foreach ($this->configElements as $config) {
            if ($theme = $config->getTheme($themeId)) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * @return ContentType[]
     */
    public function getContentTypes()
    {
        $result = [];

        foreach ($this->getConfigs() as $config) {
            if ($items = $config->getContentTypes()) {
                $result = array_merge($result, $items);
            }
        }

        return $result;
    }

    /**
     * @param string $id
     *
     * @return ContentType|null
     */
    public function getContentType($id)
    {
        foreach ($this->getConfigs() as $config) {
            if ($items = $config->getContentTypes()) {
                foreach ($items as $item) {
                    if ($item->getId() === $id) {
                        return $item;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return FieldType[]
     */
    public function getFieldTypes()
    {
        $result = [];

        foreach ($this->getConfigs() as $config) {
            if ($items = $config->getFieldTypes()) {
                $result = array_merge($result, $items);
            }
        }

        return $result;
    }

    /**
     * @param string $id
     *
     * @return FieldType|null
     */
    public function getFieldType($id)
    {
        foreach ($this->getConfigs() as $config) {
            if ($items = $config->getFieldTypes()) {
                foreach ($items as $item) {
                    if ($item->getId() === $id) {
                        return $item;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return Bundle[]
     */
    public function getConfigs()
    {
        return $this->configElements;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $result = array();
        foreach ($this->configElements as $config) {
            $value = $config->toArray();
            $value['composer'] = $config->getComposer() ?: [];
            $result[$config->getBundleName()] = $value;
        }

        return $result;
    }

    /**
     * @return \Jarves\Configuration\Bundle[]
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->configElements);
    }

    public function __sleep()
    {
        return ['configElements'];
    }

}
