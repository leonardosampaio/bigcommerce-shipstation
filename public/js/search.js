var modal = null;
var controller = null;
var signal = null;

function isJson(str) {
    try {
        return JSON.parse(str);
    } catch (e) {
        return false;
    }
}

function abortFetching() {
    console.log('Now aborting');
    finished = true;
    controller.abort();
    modal.hide();
}
function initSignal()
{
    controller = new AbortController();
    signal = controller.signal;
    signal.addEventListener("abort", () => {
        console.log("Fetch aborted");
    });
    finished = false;
}

function formatCurrency(marketCap)
{
    let symbols = {'k':3, 'M':6, 'B': 9, 'T':12}
    let finalSymbol = '';
    let finalValue = marketCap;
    for (let [symbol, power] of Object.entries(symbols)) {
        if (marketCap / Math.pow(10,power) > 1)
        {
            finalSymbol = symbol;
            finalValue = (marketCap / Math.pow(10,power)).toFixed(2);
        }
        else {
            break;
        }
    }
    return 'THB ' + finalValue + finalSymbol;
}

function download_table_as_csv(table_id, separator = ',') {
    var rows = document.querySelectorAll('table#' + table_id + ' tr');
    var csv = [];
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll('td, th');
        for (var j = 0; j < cols.length-1; j++) {
            var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ')
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(separator));
    }
    var csv_string = csv.join('\n');

    var filename = 'export_' + new Date().toLocaleDateString() + '.csv';
    var link = document.createElement('a');
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv_string));
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function setLoadingDescription(text, key, complement)
{
    document.getElementById('statusText').innerText = 
        text + key + complement;
}

var finished = false;
async function loadProgress() {
    const response = await fetch('./progress.php');
    const progress = await response.json();

    console.log('progress', progress);

    if (progress.key && progress.value)
    {
        let complement = '';
        if (progress.key == 'orders')
        {
            complement = ' (page '+progress.value+')';
        }
        else {
            complement = ' ('+progress.value+'%)';
        }
    
        setLoadingDescription('Retrieving ', progress.key, complement);
    }

    
    setTimeout(function(){ if (!finished) loadProgress(); }, 5000);
}

async function search()
{
    let ordersUrl = './orders?' + 
        'ordersUsingCache=' + document.getElementById('ordersUsingCache').checked + 
        '&groupUsingCache=' + document.getElementById('groupUsingCache').checked + 
        '&shipmentsUsingCache=' + document.getElementById('shipmentsUsingCache').checked + 
        '&productsUsingCache=' + document.getElementById('productsUsingCache').checked + 
        '&minDateCreated=' + moment(document.getElementById('begin').value, "MM/DD/YYYY").format("YYYY-MM-DD") + 
        '&maxDateCreated=' + moment(document.getElementById('end').value, "MM/DD/YYYY").format("YYYY-MM-DD")
    ;

    loadProgress();
    const ordersRawResponse = await fetch(ordersUrl, {
        method: 'GET',
        headers: {'Accept': 'application/json'},
        signal: signal
    }).then(function(response) {
        return response;
    });

    const textContent = await ordersRawResponse.text();
    finished = true;

    let content = isJson(textContent);
    if (content.error)
    {
        alert(content.error);
    }
    else
    {
        //remove old results
        Array.prototype.forEach.call( document.querySelectorAll('.novo'), function( node ) {
            node.parentNode.removeChild( node );
        });

        let count = 0;
        for (const id in content)
        {
            let order = content[id];

            let totalShipStationCost = 0;
            if (order.shipStation)
            {
                order.shipStation.forEach((shipment)=>{
                    totalShipStationCost+=shipment.shipmentCost;
                });
            }

            let customerGroup = order.group ? order.group : '';

            let novo = document.querySelector('.template').cloneNode(true);
            novo.classList.remove('template');
            novo.classList.add('novo');
            novo.querySelector('.number').textContent = (count+1);
            novo.querySelector('.customerGroup').textContent = customerGroup;
            novo.querySelector('.id').textContent = id;
            novo.querySelector('.base_shipping_cost').textContent = order.base_shipping_cost;
            novo.querySelector('.subtotal_ex_tax').textContent = order.subtotal_ex_tax;
            novo.querySelector('.total_ex_tax').textContent = order.total_ex_tax;
            novo.querySelector('.total_tax').textContent = order.total_tax;
            novo.querySelector('.total_inc_tax').textContent = order.total_inc_tax;
            novo.querySelector('.store_credit_amount').textContent = order.store_credit_amount;
            novo.querySelector('.discount_amount').textContent = order.discount_amount;
            novo.querySelector('.shipmentCost').textContent = 
                totalShipStationCost != 0 ? totalShipStationCost : '';
            

            if (order.products)
            {
                console.log('products',order.products);
            }

            count++;

            // novo.querySelector('.name').textContent = order.end_name;
            // novo.querySelector('.website').innerHTML = order.end_website ? splitWebsites(order.end_website) : '';
            // novo.querySelector('.orderbase_url').href = 'https://www.orderbase.com/price/' + order.end_name.toLowerCase().replace(' ', '-');

            // novo.querySelector('.description').textContent = order.end_description;

            // novo.querySelector('.base').textContent = order.end_base;

            // let marketCap = order.end_market_cap && order.end_market_cap > 0 
            //     ? order.end_market_cap : '';

            // if (marketCap)
            // {
            //     marketCap = formatCurrency(marketCap)
            // }

            // novo.querySelector('.market_cap').textContent = marketCap;
            // novo.querySelector('.launched_at').textContent = 
            //     order.end_launched_at ? moment(order.end_launched_at, "YYYY-MM-DD").format("DD/MM/YYYY") : '';

            document.getElementById('tableBody').appendChild(novo);
        }
    }
    setLoadingDescription('Retrieving orders', '', '');
    modal.hide();
}

window.onload = () => {

    let wrapper1 = document.getElementById('wrapper1');
    let wrapper2 = document.getElementById('wrapper2');
    wrapper1.onscroll = function() {
        wrapper2.scrollLeft = wrapper1.scrollLeft;
    };
    wrapper2.onscroll = function() {
        wrapper1.scrollLeft = wrapper2.scrollLeft;
    };

    $( "#begin" ).datepicker({
        uiLibrary: 'bootstrap4',
        format: 'mm/dd/yyyy'
    });
    $( "#end" ).datepicker({
        uiLibrary: 'bootstrap4',
        format: 'mm/dd/yyyy'
    });

    let now = new Date();
    document.querySelector('#end').value = now.toLocaleDateString('en');
    now.setDate(now.getDate() - 7);
    document.querySelector('#begin').value = now.toLocaleDateString('en');

    modal = new bootstrap.Modal(document.getElementById("modal"));

    $("#modal").on('shown.bs.modal', function(e) {
        search();
    });

    document.getElementById('submit').addEventListener("click", (e)=>{
        e.preventDefault();
        initSignal();
        modal.show();
    });

    document.getElementById('csv').addEventListener("click", (e)=>{
        e.preventDefault();
        download_table_as_csv('resultTable');
    });

    document.onkeypress = (e) => {
        if ((e.which || e.keyCode) == 13)
        { 
            modal.show();
        }
    }
};