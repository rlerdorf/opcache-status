<?php

if (!extension_loaded('Zend OPcache')) {
    echo '<div style="background-color: #F2DEDE; color: #B94A48; padding: 1em;">You do not have the Zend OPcache extension loaded, sample data is being shown instead.</div>';
    require 'data-sample.php';
}

class OpCacheDataModel
{
    private $configuration;
    private $status;

    public function __construct()
    {
        $this->configuration = opcache_get_configuration();
        $this->status = opcache_get_status();
    }

    public function getPageTitle()
    {
        return 'PHP ' . phpversion() . " with OpCache {$this->configuration['version']['version']}";
    }

    public function getStatusDataRows()
    {
        $rows = array();
        foreach ($this->status as $key => $value) {
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
                        $v = $this->size_for_humans(
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
        foreach ($this->configuration['directives'] as $key => $value) {
            if ($value === false) {
                $value = 'false';
            }
            if ($value === true) {
                $value = 'true';
            }
            if ($key == 'opcache.memory_consumption') {
                $value = $this->size_for_humans($value);
            }
            $rows[] = "<tr><th>$key</th><td>$value</td></tr>\n";
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusRows()
    {
        foreach ($this->status['scripts'] as $key => $data) {
            $dirs[dirname($key)][basename($key)] = $data;
        }

        asort($dirs);

        $id = 1;

        $rows = array();
        foreach ($dirs as $dir => $files) {
            $count = count($files);
            $file_plural = $count > 1 ? 's' : null;
            $m = 0;
            foreach ($files as $file => $data) {
                $m += $data["memory_consumption"];
            }
            $m = $this->size_for_humans($m);

            if ($count > 1) {
                $rows[] = '<tr>';
                $rows[] = "<th class=\"clickable\" id=\"head-{$id}\" colspan=\"3\" onclick=\"toggleVisible('#head-{$id}', '#row-{$id}')\">{$dir} ({$count} file{$file_plural}, {$m})</th>";
                $rows[] = '</tr>';
            }

            foreach ($files as $file => $data) {
                $rows[] = "<tr id=\"row-{$id}\">";
                $rows[] = "<td>{$data["hits"]}</td>";
                $rows[] = "<td>" . $this->size_for_humans($data["memory_consumption"]) . "</td>";
                $rows[] = $count > 1 ? "<td>{$file}</td>" : "<td>{$dir}/{$file}</td>";
                $rows[] = '</tr>';
            }

            ++$id;
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusCount()
    {
        return count($this->status["scripts"]);
    }

    public function getGraphDataSetJson()
    {
        $dataset = array();
        $dataset['memory'] = array(
            $this->status['memory_usage']['used_memory'],
            $this->status['memory_usage']['free_memory'],
            $this->status['memory_usage']['wasted_memory'],
        );

        $dataset['keys'] = array(
            $this->status['opcache_statistics']['num_cached_keys'],
            $this->status['opcache_statistics']['max_cached_keys'] - $this->status['opcache_statistics']['num_cached_keys'],
            0
        );

        $dataset['hits'] = array(
            $this->status['opcache_statistics']['misses'],
            $this->status['opcache_statistics']['hits'],
            0,
        );

        return json_encode($dataset);
    }

    public function getHumanUsedMemory()
    {
        return $this->size_for_humans($this->getUsedMemory());
    }

    public function getHumanFreeMemory()
    {
        return $this->size_for_humans($this->getFreeMemory());
    }

    public function getHumanWastedMemory()
    {
        return $this->size_for_humans($this->getWastedMemory());
    }

    public function getUsedMemory()
    {
        return $this->status['memory_usage']['used_memory'];
    }

    public function getFreeMemory()
    {
        return $this->status['memory_usage']['free_memory'];
    }

    public function getWastedMemory()
    {
        return $this->status['memory_usage']['wasted_memory'];
    }

    public function getWastedMemoryPercentage()
    {
        return number_format($this->status['memory_usage']['current_wasted_percentage'], 2);
    }

    private function size_for_humans($bytes)
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

}

$dataModel = new OpCacheDataModel();
?>
<!DOCTYPE html>
<meta charset="utf-8">
<html>
<head>
<style>
body {
	font-family: "Helvetica Neue",Helvetica,Arial,sans-serif;
	margin: auto;
	position: relative;
	width: 1024px;
}

h1 {
	padding: 10px 0
}

table {
	border-collapse: collapse;
}

tbody tr:nth-child(even) {
	background-color: #eee
}

p.capitalize {
	text-transform: capitalize
}

.tabs {
	position: relative;
	float: left;
	width: 60%;
}

.tab {
	float: left
}

.tab label {
	background: #eee;
	padding: 10px;
	border: 1px solid #ccc;
	margin-left: -1px;
	position: relative;
	left: 1px;
}

.tab [type=radio] {
	display: none
}

.tab th, .tab td {
	padding: 6px 10px
}

.content {
	position: absolute;
	top: 28px;
	left: 0;
	background: white;
	padding: 20px;
	border: 1px solid #ccc;
	height: 500px;
	width: 560px;
	overflow: auto;
}

.content table {
	width: 100%
}

.content th, .tab:nth-child(3) td {
	text-align: left;
}

.content td {
	text-align: right;
}

.clickable {
	cursor: pointer;
}

[type=radio]:checked ~ label {
	background: white;
	border-bottom: 1px solid white;
	z-index: 2;
}

[type=radio]:checked ~ label ~ .content {
	z-index: 1;
}

#graph {
	float: right;
	width: 40%;
	position: relative;
}

#graph > form {
	position: absolute;
	right: 110px;
	top: -20px;
}

#graph > svg {
	position: absolute;
	top: 0;
	right: 0;
}

#stats {
	position: absolute;
	right: 125px;
	top: 145px;
}

#stats th, #stats td {
	padding: 6px 10px;
	font-size: 0.8em;
}
</style>
<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/d3/3.0.1/d3.v3.min.js"></script>
<script type="text/javascript">
var hidden = {};
function toggleVisible(head, row) {
	if (!hidden[row]) {
		d3.selectAll(row).transition().style('display', 'none');
		hidden[row] = true;
		d3.select(head).transition().style('color', '#ccc');
	} else {
		d3.selectAll(row).transition().style('display');
		hidden[row] = false;
		d3.select(head).transition().style('color', '#000');
	}
}
</script>
<title><?= $dataModel->getPageTitle(); ?></title>
</head>

<body>
	<h1><?= $dataModel->getPageTitle(); ?></h1>

	<div class="tabs">

		<div class="tab">
			<input type="radio" id="tab-status" name="tab-group-1" checked>
			<label for="tab-status">Status</label>
			<div class="content">
				<table>
					<?= $dataModel->getStatusDataRows(); ?>
				</table>
			</div>
		</div>

		<div class="tab">
			<input type="radio" id="tab-config" name="tab-group-1">
			<label for="tab-config">Configuration</label>
			<div class="content">
				<table>
					<?= $dataModel->getConfigDataRows(); ?>
				</table>
			</div>
		</div>

		<div class="tab">
			<input type="radio" id="tab-scripts" name="tab-group-1">
			<label for="tab-scripts">Scripts (<?= $dataModel->getScriptStatusCount(); ?>)</label>
			<div class="content">
				<table style="font-size:0.8em;">
					<tr>
						<th width="10%">Hits</th>
						<th width="20%">Memory</th>
						<th width="70%">Path</th>
					</tr>
					<?= $dataModel->getScriptStatusRows(); ?>
				</table>
			</div>
		</div>

	</div>

	<div id="graph">
		<form>
			<label><input type="radio" name="dataset" value="memory" checked> Memory</label>
			<label><input type="radio" name="dataset" value="keys"> Keys</label>
			<label><input type="radio" name="dataset" value="hits"> Hits</label>
		</form>

		<div id="stats"></div>
	</div>

    <?= "
	<script>
	var dataset = {$dataModel->getGraphDataSetJson()};
	";
    ?>

	var width = 400,
			height = 400,
			radius = Math.min(width, height) / 2,
			colours = ['#B41F1F', '#1FB437', '#ff7f0e'];
	d3.scale.customColours = function() {
		return d3.scale.ordinal().range(colours);
	};
	var colour = d3.scale.customColours();
	var pie = d3.layout.pie()
			.sort(null);

	var arc = d3.svg.arc()
			.innerRadius(radius - 20)
			.outerRadius(radius - 50);
	var svg = d3.select("#graph").append("svg")
			.attr("width", width)
			.attr("height", height)
			.append("g")
			.attr("transform", "translate(" + width / 2 + "," + height / 2 + ")");

	var path = svg.selectAll("path")
			.data(pie(dataset.memory))
			.enter().append("path")
			.attr("fill", function(d, i) { return colour(i); })
			.attr("d", arc)
			.each(function(d) { this._current = d; }); // store the initial values

	d3.selectAll("input").on("change", change);
	set_text("memory");

	function set_text(t) {
		if(t=="memory") {
			d3.select("#stats").html(
				"<table><tr><th style='background:#B41F1F;'>Used</th><td><?= $dataModel->getHumanUsedMemory()?></td></tr>"+
				"<tr><th style='background:#1FB437;'>Free</th><td><?= $dataModel->getHumanFreeMemory()?></td></tr>"+
				"<tr><th style='background:#ff7f0e;' rowspan=\"2\">Wasted</th><td><?= $dataModel->getHumanWastedMemory()?></td></tr>"+
				"<tr><td><?= $dataModel->getWastedMemoryPercentage()?>%</td></tr></table>"
			);
		} else if(t=="keys") {
			d3.select("#stats").html(
				"<table><tr><th style='background:#B41F1F;'>Cached keys</th><td>"+dataset[t][0]+"</td></tr>"+
				"<tr><th style='background:#1FB437;'>Free Keys</th><td>"+dataset[t][1]+"</td></tr></table>"
			);
		} else if(t=="hits") {
			d3.select("#stats").html(
				"<table><tr><th style='background:#B41F1F;'>Misses</th><td>"+dataset[t][0]+"</td></tr>"+
				"<tr><th style='background:#1FB437;'>Cache Hits</th><td>"+dataset[t][1]+"</td></tr></table>"
			);
		}
	}

	function change() {
		path = path.data(pie(dataset[this.value])); // update the data
		path.transition().duration(750).attrTween("d", arcTween); // redraw the arcs
		set_text(this.value);
	}
	function arcTween(a) {
		var i = d3.interpolate(this._current, a);
		this._current = i(0);
		return function(t) {
			return arc(i(t));
		};
	}
	</script>
</body>
</html>