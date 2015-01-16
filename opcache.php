<?php
require_once __DIR__.'/vendor/autoload.php';

use lerdorf\opcache\opCacheDataModel;

$dataModel = new OpCacheDataModel();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="assets/css/style.css" />
    <script src="//cdnjs.cloudflare.com/ajax/libs/d3/3.0.1/d3.v3.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
    <script src="assets/js/headscript.js"></script>
    <title><?php echo $dataModel->getPageTitle(); ?></title>
</head>

<body>
    <div id="container">
        <h1><?php echo $dataModel->getPageTitle(); ?></h1>

        <div class="tabs">

            <div class="tab">
                <input type="radio" id="tab-status" name="tab-group-1" checked>
                <label for="tab-status">Status</label>
                <div class="content">
                    <table>
                        <?php echo $dataModel->getStatusDataRows(); ?>
                    </table>
                </div>
            </div>

            <div class="tab">
                <input type="radio" id="tab-config" name="tab-group-1">
                <label for="tab-config">Configuration</label>
                <div class="content">
                    <table>
                        <?php echo $dataModel->getConfigDataRows(); ?>
                    </table>
                </div>
            </div>

            <div class="tab">
                <input type="radio" id="tab-scripts" name="tab-group-1">
                <label for="tab-scripts">Scripts (<?php echo $dataModel->getScriptStatusCount(); ?>)</label>
                <div class="content">
                    <table class="smaller">
                        <tr>
                            <th width="10%">Hits</th>
                            <th width="20%">Memory</th>
                            <th width="70%">Path</th>
                        </tr>
                        <?php echo $dataModel->getScriptStatusRows(); ?>
                    </table>
                </div>
            </div>

            <div class="tab">
                <input type="radio" id="tab-visualise" name="tab-group-1">
                <label for="tab-visualise">Visualise Partition</label>
                <div class="content"></div>
            </div>

        </div>

        <div id="graph">
            <form>
                <label><input type="radio" name="dataset" value="memory" checked> Memory</label>
                <label><input type="radio" name="dataset" value="keys"> Keys</label>
                <label><input type="radio" name="dataset" value="hits"> Hits</label>
                <label><input type="radio" name="dataset" value="restarts"> Restarts</label>
            </form>

            <div id="stats">
                <table>
                    <tr><th class="err">Used</th><td><?php echo $dataModel->getHumanUsedMemory()?></td></tr>
                    <tr><th class="fine">Free</th><td><?php echo $dataModel->getHumanFreeMemory()?></td></tr>
                    <tr><th class="warn" rowspan="2">Wasted</th><td><?php echo $dataModel->getHumanWastedMemory()?></td></tr>
                    <tr><td><?php echo $dataModel->getWastedMemoryPercentage()?>%</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div id="close-partition">&#10006; Close Visualisation</div>
    <div id="partition"></div>
    <textarea id="dataset"><?php echo $dataModel->getGraphDataSetJson(); ?></textarea>
    <textarea id="d3data"><?php echo json_encode($dataModel->getD3Scripts()); ?></textarea>
    <script src="assets/js/bodyscript.js"></script>
</body>
</html>
