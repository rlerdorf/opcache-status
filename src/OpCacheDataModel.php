<?php

namespace lerdorf\opcache;
use lerdorf\opcache\messages;

if (!extension_loaded('Zend OPcache')) {
    echo sprintf('<div class="error">%s</div>', Messages/ERR_NO_ZEND_APC);
    require 'data-sample.php';
}

class OpCacheDataModel
{
    private $_configuration;
    private $_status;
    private $_d3Scripts = array();

    public function __construct()
    {
        $this->_configuration = opcache_get_configuration();
        $this->_status = opcache_get_status();
    }

    public function getPageTitle()
    {
        return 'PHP ' . phpversion() . " with OpCache {$this->_configuration['version']['version']}";
    }

    public function getStatusDataRows()
    {
        $rows = array();
        foreach ($this->_status as $key => $value) {
            if ($key === 'scripts') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v === false) {
                        $value = 'false';
                    }
                    if ($v === true) {
                        $value = 'true';
                    }
                    if ($k === 'used_memory' || $k === 'free_memory' || $k === 'wasted_memory') {
                        $v = $this->_size_for_humans(
                            $v
                        );
                    }
                    if ($k === 'current_wasted_percentage' || $k === 'opcache_hit_rate') {
                        $v = number_format(
                                $v,
                                2
                            ) . '%';
                    }
                    if ($k === 'blacklist_miss_ratio') {
                        $v = number_format($v, 2) . '%';
                    }
                    if ($k === 'start_time' || $k === 'last_restart_time') {
                        $v = ($v ? date(DATE_RFC822, $v) : 'never');
                    }

                    $rows[] = "<tr><th>$k</th><td>$v</td></tr>\n";
                }
                continue;
            }
            if ($value === false) {
                $value = 'false';
            }
            if ($value === true) {
                $value = 'true';
            }
            $rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
        }

        return implode("\n", $rows);
    }

    public function getConfigDataRows()
    {
        $rows = array();
        foreach ($this->_configuration['directives'] as $key => $value) {
            if ($value === false) {
                $value = 'false';
            }
            if ($value === true) {
                $value = 'true';
            }
            if ($key == 'opcache.memory_consumption') {
                $value = $this->_size_for_humans($value);
            }
            $rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusRows()
    {
        foreach ($this->_status['scripts'] as $key => $data) {
            $dirs[dirname($key)][basename($key)] = $data;
            $this->_arrayPset($this->_d3Scripts, $key, array(
                'name' => basename($key),
                'size' => $data['memory_consumption'],
            ));
        }

        asort($dirs);

        $basename = '';
        while (true) {
            if (count($this->_d3Scripts) !=1) break;
            $basename .= DIRECTORY_SEPARATOR . key($this->_d3Scripts);
            $this->_d3Scripts = reset($this->_d3Scripts);
        }

        $this->_d3Scripts = $this->_processPartition($this->_d3Scripts, $basename);
        $id = 1;

        $rows = array();
        foreach ($dirs as $dir => $files) {
            $count = count($files);
            $file_plural = $count > 1 ? 's' : null;
            $m = 0;
            foreach ($files as $file => $data) {
                $m += $data["memory_consumption"];
            }
            $m = $this->_size_for_humans($m);

            if ($count > 1) {
                $rows[] = '<tr>';
                $rows[] = "<th class=\"clickable\" id=\"head-{$id}\" colspan=\"3\" onclick=\"toggleVisible('#head-{$id}', '#row-{$id}')\">{$dir} ({$count} file{$file_plural}, {$m})</th>";
                $rows[] = '</tr>';
            }

            foreach ($files as $file => $data) {
                $rows[] = "<tr id=\"row-{$id}\">";
                $rows[] = "<td>{$data["hits"]}</td>";
                $rows[] = "<td>" . $this->_size_for_humans($data["memory_consumption"]) . "</td>";
                $rows[] = $count > 1 ? "<td>{$file}</td>" : "<td>{$dir}/{$file}</td>";
                $rows[] = '</tr>';
            }

            ++$id;
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusCount()
    {
        return count($this->_status["scripts"]);
    }

    public function getGraphDataSetJson()
    {
        $dataset = array();
        $dataset['memory'] = array(
            $this->_status['memory_usage']['used_memory'],
            $this->_status['memory_usage']['free_memory'],
            $this->_status['memory_usage']['wasted_memory'],
        );

        $dataset['keys'] = array(
            $this->_status['opcache_statistics']['num_cached_keys'],
            $this->_status['opcache_statistics']['max_cached_keys'] - $this->_status['opcache_statistics']['num_cached_keys'],
            0
        );

        $dataset['hits'] = array(
            $this->_status['opcache_statistics']['misses'],
            $this->_status['opcache_statistics']['hits'],
            0,
        );

        $dataset['restarts'] = array(
            $this->_status['opcache_statistics']['oom_restarts'],
            $this->_status['opcache_statistics']['manual_restarts'],
            $this->_status['opcache_statistics']['hash_restarts'],
        );

        return json_encode($dataset);
    }

    public function getHumanUsedMemory()
    {
        return $this->_size_for_humans($this->getUsedMemory());
    }

    public function getHumanFreeMemory()
    {
        return $this->_size_for_humans($this->getFreeMemory());
    }

    public function getHumanWastedMemory()
    {
        return $this->_size_for_humans($this->getWastedMemory());
    }

    public function getUsedMemory()
    {
        return $this->_status['memory_usage']['used_memory'];
    }

    public function getFreeMemory()
    {
        return $this->_status['memory_usage']['free_memory'];
    }

    public function getWastedMemory()
    {
        return $this->_status['memory_usage']['wasted_memory'];
    }

    public function getWastedMemoryPercentage()
    {
        return number_format($this->_status['memory_usage']['current_wasted_percentage'], 2);
    }

    public function getD3Scripts()
    {
        return $this->_d3Scripts;
    }

    private function _processPartition($value, $name = null)
    {
        if (array_key_exists('size', $value)) {
            return $value;
        }

        $array = array('name' => $name,'children' => array());

        foreach ($value as $k => $v) {
            $array['children'][] = $this->_processPartition($v, $k);
        }

        return $array;
    }

    private function _size_for_humans($bytes)
    {
        if ($bytes > 1048576) {
            return sprintf('%.2f&nbsp;MB', $bytes / 1048576);
        } else {
            if ($bytes > 1024) {
                return sprintf('%.2f&nbsp;kB', $bytes / 1024);
            } else {
                return sprintf('%d&nbsp;bytes', $bytes);
            }
        }
    }

    // Borrowed from Laravel
    private function _arrayPset(&$array, $key, $value)
    {
        if (is_null($key)) return $array = $value;
        $keys = explode(DIRECTORY_SEPARATOR, ltrim($key, DIRECTORY_SEPARATOR));
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if ( ! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = array();
            }
            $array =& $array[$key];
        }
        $array[array_shift($keys)] = $value;
        return $array;
    }
}
