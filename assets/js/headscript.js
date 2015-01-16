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
