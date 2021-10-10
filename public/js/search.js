var modal = null;

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

function splitWebsites(urlsStr)
{
    let html = '';

    let arr = urlsStr.split(" ");
    urlsStr.split(" ").forEach((url,k)=>{
        html += '<a href="'+url+'" target="_blank">'+url+'</a>';
        if (k+1 != arr.length)
        {
            html += '<br>';
        }
    });
    
    return html;
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

async function search()
{
    //fixed
    const groupId = 12;
    
    let customersInGroupUrl = './customers-in-group?' + 
    'useCache=' + document.getElementById('groupUsingCache').checked + 
    '&groupId=' + groupId
    ;
    
    const customersRawResponse = await fetch(customersInGroupUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        }
    }).then(function(response) {
        return response;
    });
    
    let customers = await customersRawResponse.json();
    
    let ordersUrl = './orders?' + 
        'useCache=' + document.getElementById('ordersUsingCache').checked + 
        '&minDateCreated=' + moment(document.getElementById('begin').value, "MM/DD/YYYY").format("YYYY-MM-DD") + 
        '&maxDateCreated=' + moment(document.getElementById('end').value, "MM/DD/YYYY").format("YYYY-MM-DD")
    ;

    const ordersRawResponse = await fetch(ordersUrl, {
        method: 'GET',
        headers: {
        'Accept': 'application/json'
        }
    }).then(function(response) {
        return response;
    });

    const content = await ordersRawResponse.json();

    if (content.orders)
    {
        //remove old results
        Array.prototype.forEach.call( document.querySelectorAll('.novo'), function( node ) {
            node.parentNode.removeChild( node );
        });

        document.querySelector('#totalResults').textContent = content.orders.length;

        let productsPromises = [];
        let shipmentsPromises = [];

        let count = 0;
        content.orders.forEach((order) => {

            for (let customer of customers.customers)
            {
                if (customer.id == order.customer_id)
                {
                    let productsUrl = './products-in-order?' + 
                        'useCache=' + document.getElementById('productsUsingCache').checked + 
                        '&orderId=' + order.id
                    ;

                    productsPromises.push(productsUrl);

                    let shipmentUrl = './shipment?' + 
                        'useCache=' + document.getElementById('shipmentsUsingCache').checked + 
                        '&orderNumber=' + order.id
                    ;

                    shipmentsPromises.push(shipmentUrl);

                    let novo = document.querySelector('.template').cloneNode(true);

                    novo.classList.remove('template');
                    novo.classList.add('novo');

                    novo.querySelector('.number').textContent = (count+1);
                    count++;
                    novo.querySelector('.name').textContent = order.end_name;
                    novo.querySelector('.website').innerHTML = order.end_website ? splitWebsites(order.end_website) : '';
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
                    break;
                }
            }
        });

        Promise.all(productsPromises.map(u=>{
            // console.log('product ' + i);
            return fetch(u);
        }))
        .then(responses => {
            Promise.all(responses.map(res => res.json()))
        })
        .then(jsons => {
            console.log(jsons);
        });

        Promise.all(shipmentsPromises.map(u=>fetch(u))).then(responses =>
            Promise.all(responses.map(res => res.json()))
        ).then(jsons => {
            console.log(jsons);
            modal.hide();
        });

    }
    else if (content.error)
    {
        alert(content.error);
    }
}

window.onload = () => {
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