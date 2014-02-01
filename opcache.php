<?php
/*
* Fetch configuration and status information from OpCache
*/
$config = opcache_get_configuration();
$status = opcache_get_status();

/*
* Turn bytes into a human readable format
* @param $bytes
*/
function size_for_humans($bytes) {
    if ($bytes > 1048576) {
        return sprintf("%.2f MB", $bytes/1048576);
    } else if ($bytes > 1024) {
        return sprintf("%.2f KB", $bytes/1024);
    } else return sprintf("%d bytes", $bytes);
}

/*
* Borrowed from Laravel
 */
function array_set(&$array, $key, $value)
{
  if (is_null($key)) return $array = $value;
  $keys = explode(DIRECTORY_SEPARATOR, ltrim($key,DIRECTORY_SEPARATOR));
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

?>
<!DOCTYPE html>
<meta charset="utf-8">
<html><head>
<style>
body{
    font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;
    margin:auto;
    position:relative;
    width:1024px;
}
text{
    font:10px sans-serif;
}
form{
    position: absolute;
    right:210px;
    top:50px;
}
#graph{
    position: absolute;
    right:0px;
    top:80px;
}
#stats{
    position: absolute;
    right:234px;
    top:240px;
}
tbody tr:nth-child(even) {
    background-color:#eee;
}
p.capitalize{
    text-transform:capitalize;
}
.tabs{
    position:relative;
    min-height:200px;
    clear:both;
    margin:25px 0;
}
.tab{
    float:left;
}
.tab label{
    background: #eee;
    padding:10px;
    border:1px solid #ccc;
    margin-left:-1px;
    position:relative;
    left:1px;
}
.tab [type=radio]{
    display: none;
}
.content{
    position:absolute;
    top:28px;
    left: 0;
    background:white;
    padding:20px;
    border:1px solid #ccc;
    height:500px;
    width:480px;
    overflow-y:auto;
    overflow-x:hidden;
}
.content table {
    width:100%;
}
[type=radio]:checked ~ label{
    background: white;
    border-bottom:1px solid white;
    z-index:2;
}
[type=radio]:checked ~ label ~ .content{
    z-index: 1;
}
#partition {
  position: absolute;
  top: 650px;
  font-size: 11px;
  padding-bottom: 20px;
}


rect {
  stroke: #eee;
  fill: #aaa;
  fill-opacity: 1;
}

rect.parent {
  cursor: pointer;
  fill: steelblue;
}

text {
  pointer-events: none;
}

    </style>
<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/d3/3.0.1/d3.v3.min.js"></script>
<script language="javascript">
var hidden = {};
function toggleVisible(head, row) {
    if (!hidden[row]) {
        d3.selectAll(row)
            .transition().style('display', 'none');
        hidden[row] = true;
        d3.select(head).transition().style('color', '#ccc');
    } else {
        d3.selectAll(row)
            .transition().style('display');
        hidden[row] = false;
        d3.select(head).transition().style('color', '#000');
    }
}
</script>
</head>
<body>
  <h1>PHP <?= phpversion()?> OpCache <?= $config['version']['version']?></h1>
  <form>
    <label><input type="radio" name="dataset" value="memory" checked> Memory</label>
    <label><input type="radio" name="dataset" value="keys"> Keys</label>
    <label><input type="radio" name="dataset" value="hits"> Hits</label>
  </form>
  <div id="stats">
  </div>
  <div class="tabs">

    <div class="tab">
      <input type="radio" id="tab-status" name="tab-group-1" checked>
      <label for="tab-status">Status</label>
      <div class="content">
      <table>
<?php
foreach($status as $key=>$value) {
  if($key=='scripts') continue;
  if(is_array($value)) {
    foreach($value as $k=>$v) {
      if($v===false) $value = "false";
      if($v===true) $value = "true";
      if($k=='current_wasted_percentage' || $k=='opcache_hit_rate') $v = number_format($v,2).'%';
      if($k=='blacklist_miss_ratio') $v = number_format($v,2);
      echo "<tr><th align=\"left\">$k</th><td align=\"right\">$v</td></tr>\n";
    }
    continue;
  }
  if($value===false) $value = "false";
  if($value===true) $value = "true";
  echo "<tr><th align=\"left\">$key</th><td align=\"right\">$value</td></tr>\n";
}
?>
      </table>
      </div>
    </div>

    <div class="tab">
      <input type="radio" id="tab-config" name="tab-group-1">
      <label for="tab-config">Configuration</label>
      <div class="content">
      <table>
<?php
foreach($config['directives'] as $key=>$value) {
  if($value===false) $value = "false";
  if($value===true) $value = "true";
  echo "<tr><th align=\"left\">$key</th><td align=\"right\">$value</td></tr>\n";
}
?>
      </table>
      </div>
    </div>

    <div class="tab">
      <input type="radio" id="tab-scripts" name="tab-group-1">
      <label for="tab-scripts">Scripts</label>
      <div class="content">
      <table style="font-size:0.8em;">
      <tr>
        <th width="10%">Hits</th>
        <th width="15%">Memory</th>
        <th width="75%">Path</th>
      </tr>
<?php
$d3scripts = array();

foreach($status['scripts'] as $key=>$data) {
    $dirs[dirname($key)][basename($key)]=$data;
    array_set($d3scripts, $key, array(
      'name' => basename($key),
      'size' => $data['memory_consumption'],
    ));
}

asort($dirs);

$id = 1;

foreach($dirs as $dir => $files) {
    $count = count($files);

    if ($count > 1) {
        echo "<tr>";
        echo "<th id=\"head-{$id}\" colspan=\"3\" onclick=\"toggleVisible('#head-{$id}', '#row-{$id}')\">{$dir} ({$count} files)</th>";
        echo "</tr>";
    }

    foreach ($files as $file => $data) {
        echo "<tr id=\"row-{$id}\">";
        echo "<td>{$data["hits"]}</td>";
        echo "<td>" .size_for_humans($data["memory_consumption"]). "</td>";

        if ($count > 1) {
            echo "<td>{$file}</td>";
        } else echo "<td>{$dir}/{$file}</td>";

        echo "</tr>";
    }

    ++$id;
}
?>
      </table>
      </div>
    </div>

  </div>

  <div id="graph">
  </div>

<?php
$mem = $status['memory_usage'];
$stats = $status['opcache_statistics'];
$free_keys = $stats['max_cached_keys'] - $stats['num_cached_keys'];
echo <<<EOB
<script>
var dataset = {
  memory: [{$mem['used_memory']},{$mem['free_memory']},{$mem['wasted_memory']}],
  keys: [{$stats['num_cached_keys']},{$free_keys},0],
  hits: [{$stats['hits']},{$stats['misses']},0]
};
EOB;
?>
var width = 600,
    height = 400,
    radius = Math.min(width, height) / 2;
var color = d3.scale.category20();
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
    .attr("fill", function(d, i) { return color(i); })
    .attr("d", arc)
    .each(function(d) { this._current = d; }); // store the initial values

d3.selectAll("input").on("change", change);
set_text("memory");

function set_text(t) {
  if(t=="memory") {
    d3.select("#stats").html(
      "<table><tr><th style='background:#1f77b4;' align=right>Used</th><td align=right><?php echo size_for_humans($mem['used_memory'])?></td></tr>"+
      "<tr><th style='background:#aec7e8;' align=right>Free</th><td align=right><?php echo size_for_humans($mem['free_memory'])?></td></tr>"+
      "<tr><th style='background:#ff7f0e;' align=right>Wasted</th><td align=right><?php echo size_for_humans($mem['wasted_memory'])?></td></tr>"+
      "<tr><th style='background:#ff7f0e;'> </th><td align=right><?php echo number_format($mem['current_wasted_percentage'],2)?>%</td></tr></table>"
    );
  } else if(t=="keys") {
    d3.select("#stats").html(
      "<table><tr><th style='background:#1f77b4;'>Cached keys</th><td align=right>"+dataset[t][0]+"</td></tr>"+
      "<tr><th style='background:#aec7e8;'>Free Keys</th><td align=right>"+dataset[t][1]+"</td></tr></table>"
    );
  } else if(t=="hits") {
    d3.select("#stats").html(
      "<table><tr><th style='background:#1f77b4;' align=right>Cache Hits</th><td align=right>"+dataset[t][0]+"</td></tr>"+
      "<tr><th style='background:#aec7e8;' align=right>Misses</th><td align=right>"+dataset[t][1]+"</td></tr></table>"
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

<div id="partition"></div>

<?php

function processd3( $value, $name=null )
{
  if (array_key_exists('size',$value)) {
    return $value;
  }
  $array = array('name'=>$name,'children'=>array());
  foreach($value as $k=>$v)
  {
    $array['children'][] = processd3($v, $k);
  }
  return $array;
}

$basename = '';
while(true) {
  if( count($d3scripts)==1 ) {
    $basename .= DIRECTORY_SEPARATOR . key($d3scripts);
    $d3scripts = reset($d3scripts);
  } else {
    break;
  }
}

$d3scripts = processd3($d3scripts, $basename);

?>
<script type="text/javascript">

function size_for_humans(bytes) {
  if (bytes > 1048576) {
      return (bytes/1048576).toFixed(2) + ' MB';
  } else if (bytes > 1024) {
      return (bytes/1024).toFixed(2) + ' KB';
  } else return bytes + ' bytes';
}

var w = 1024,
    h = 600,
    x = d3.scale.linear().range([0, w]),
    y = d3.scale.linear().range([0, h]);

var vis = d3.select("#partition")
    .style("width", w + "px")
    .style("height", h + "px")
  .append("svg:svg")
    .attr("width", w)
    .attr("height", h);

var partition = d3.layout.partition()
    .value(function(d) { return d.size; });

  root = JSON.parse('<?php echo json_encode($d3scripts); ?>');

  var g = vis.selectAll("g")
      .data(partition.nodes(root))
    .enter().append("svg:g")
      .attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; })
      .on("click", click);

  var kx = w / root.dx,
      ky = h / 1;

  g.append("svg:rect")
      .attr("width", root.dy * kx)
      .attr("height", function(d) { return d.dx * ky; })
      .attr("class", function(d) { return d.children ? "parent" : "child"; });

  g.append("svg:text")
      .attr("transform", transform)
      .attr("dy", ".35em")
      .style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; })
      .text(function(d) { return d.name; })

  d3.select(window)
      .on("click", function() { click(root); })

  function click(d) {
    if (!d.children) return;

    kx = (d.y ? w - 40 : w) / (1 - d.y);
    ky = h / d.dx;
    x.domain([d.y, 1]).range([d.y ? 40 : 0, w]);
    y.domain([d.x, d.x + d.dx]);

    var t = g.transition()
        .duration(d3.event.altKey ? 7500 : 750)
        .attr("transform", function(d) { return "translate(" + x(d.y) + "," + y(d.x) + ")"; });

    t.select("rect")
        .attr("width", d.dy * kx)
        .attr("height", function(d) { return d.dx * ky; });

    t.select("text")
        .attr("transform", transform)
        .style("opacity", function(d) { return d.dx * ky > 12 ? 1 : 0; });

    d3.event.stopPropagation();
  }

  function transform(d) {
    return "translate(8," + d.dx * ky / 2 + ")";
  }


</script>
</body>
</html>
