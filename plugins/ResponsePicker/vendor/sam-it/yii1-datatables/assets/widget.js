/*
 * Add some extra functionality for easier usage within Yii.
 */
$(document).ready(function() {
	// Clicking on a remove row link will remove it from the table.
	$('body').on('click', 'table.dataTable a.removerow', function() {
		$(this).closest('table').dataTable({"bRetrieve" : true}).fnDeleteRow($(this).closest('tr'));
	});

	// Handle row selection for single select.
	$('body').on('click', 'table.dataTable.singleSelect tbody tr', function() {
		if (!$(this).hasClass('selected'))
		{
			$(this).parent().children().removeClass('selected');
			$(this).addClass('selected');
			$(this).find('input.select-on-check').attr('checked', true);
		}
		else
		{
			$(this).removeClass('selected');
		}
	});

	// Handle multiple select.
	$('body').on('click', 'table.dataTable.multiSelect tbody tr', function() {
		var $this = $(this);
		$this.toggleClass('selected');
		$this.find('input.select-on-check').prop('checked', $this.hasClass('selected'));
		$this.trigger('change');
	});

	// Don't propagate click events for inputs.'
	$('body').on('click', 'table.dataTable.multiSelect tbody tr input', function(e) {
		e.stopPropagation();
	});

	$('body').on('click', 'table.dataTable.multiSelect tbody tr input.select-on-check', function(e) {
		e.stopPropagation();
		var $grid = $(this).closest('table');
		var $checks = $('input.select-on-check', $grid);
		if (this.checked)
		{
			$(this).closest('tr').addClass('selected');
		}
		else
		{
			$(this).closest('tr').removeClass('selected');
		}
		$("input.select-on-check-all", $grid).prop('checked', $checks.length === $checks.filter(':checked').length);

	});

	$('body').on('click', 'table.dataTable.multiSelect thead tr input.select-on-check-all', function(e) {
		e.stopPropagation();
		if (this.checked)
		{
			$(this).closest('table').find('tbody tr').addClass('selected');
			$(this).closest('table').find('tbody tr input.select-on-check').prop('checked', true);
		}
		else
		{
			$(this).closest('table').find('tbody tr').removeClass('selected');
			$(this).closest('table').find('tbody tr input.select-on-check').prop('checked', false);
		}
	});


	/**
	 * Filter hooks
	 */
	$('body').on('keyup', 'table.dataTable tr.filters input', function(e) {
		$(this).closest('table').dataTable().fnFilter($(this).val(), $(this).parent().index());
	});
	
	$('body').on('change', 'table.dataTable tr.filters select:not(.strict)', function(e) {
		$(this).closest('table').dataTable().api().columns($(this).parent().index()).search($(this).val(), false, true).draw();
	});

	$('body').on('change', 'table.dataTable tr.filters select.strict', function(e) {
		if ($(this).val() == "") {
			$(this).closest('table').dataTable().api().columns($(this).parent().index()).search("", false, false).draw();
		} else {
			$(this).closest('table').dataTable().api().columns($(this).parent().index()).search("^" + $(this).val().replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&") + "$", true, false).draw();
		}
	});

	$('body').on('init.dt', 'table.dataTable', function(e, settings, json) {
		// Override the fnLog function.
		settings.oApi._fnLog = function ( settings, level, msg, tn ) {
			msg = 'DataTables warning: '+
				(settings!==null ? 'table id='+settings.sTableId+' - ' : '')+msg;

			if ( tn ) {
				msg += '. For more information about this error, please see '+
				'http://datatables.net/tn/'+tn;
			}

			console.log("DataTable: " + msg);
		};

		settings.oApi.fnUpdateFilters.call(this, settings, json);
	});
});
	$('body').on('processing.dt', 'table.dataTable', function(e, settings, processing) {
		if (processing) {
            $(this).parent().parent().parent().trigger('startLoading');
		} else {
            $(this).parent().parent().parent().trigger('endLoading');
		}
	});

	$('body').on('xhr.dt', 'table.dataTable', function(e, settings, json) {
        if (typeof json.scripts != 'undefined') {
            var scripts = json.scripts;
            var func = function($) { 
                var jQuery = $;
                eval(scripts);
            };
            delete json['scripts'];
            settings.oInstance.one('draw.dt', function(e, settings) { func(settings.oInstance.api().$); });
        }
		$.fn.dataTableExt.oApi.fnUpdateFilters.call(this, settings, json);
	});


//		$(this).one('draw.dt', function() {
//			$(this).trigger('dataload.dt', [settings, json]);
//		});
	/*
	 * Update filters
	 */
	$('table.dataTable').on('dataload.dt', $.fn.dataTableExt.oApi.fnUpdateFilters);

(function($) {
	/*
	 * Function: fnUpdateFilters
	 * Purpose:  Updates the dropdown filters.
	 * Returns:  null
	 * Inputs:   object: oSettings - dataTable settings object. This is always the last argument passed to the function
	 *           json: The json data that was loaded.
	 * Author:   Sam Mousa (sam@befound.nl);
	 */
	$.fn.dataTableExt.oApi.fnUpdateFilters = function(settings, json)
	{
		var api = settings.oInstance.api();
		for (var i in settings.aoColumns)
		{
			var column = settings.aoColumns[i];
			if (typeof column.sFilter == 'string' && column.sFilter.substr(0, 6) == 'select')
			{
				var select = $('tr.filters th:nth(' + i + ') select')
				select.find('option').remove();
                select.append('<option value="">' + settings.oLanguage.filter.none + '</option>');
				if (typeof json != 'undefined')
				{
					json.data.map(function (currentValue, index) {
						return currentValue[column.data];
					}).sort().forEach(function(value, index, arr) {
						// They are already sorted.
						if (index == 0 || arr[index-1] != value)
						{
							var $tag = $('<option>').html(value).appendTo(select);
                            $tag.attr('value', $tag.text());
						}
					});
				}
				else
				{
					api.columns(i).data().eq(0).sort().unique().each(function(item)
					{
                        var $tag = $('<option>').html(item).appendTo(select);
                        $tag.attr('value', $tag.text());
						
					});
				}
				select.trigger('change');
			}
		}

	}

	/*
	 * Function: fnAddMetaData
	 * Purpose:  Function for adding metadata to rows. Data is passed in the column named metaData.
	 * Returns:  null
	 * Inputs:   object: oSettings - dataTable settings object. This is always the last argument past to the function
	 *           DOMElement : nRow - The tr element.
	 *           object: aData - The data object
	 * Author:   Sam Mousa (sam@befound.nl);
	 */
	$.fn.dataTableExt.oApi.fnAddMetaData = function (oSettings, nRow, aData)
	{
		if ($.isPlainObject(aData.metaData))
		{
			$(nRow).attr(aData.metaData);
		}
	}

	$.valHooks['DataTableCheckBoxList'] = {
		"get" : function(el) {
			c = [];
			$(el).find('tbody :checked').each(function () {
				c.push(this.value);
			});
			return c.join(',');
		}
	};
})(jQuery);


jQuery.extend( jQuery.fn.dataTableExt.oSort, {
    "alt-string-pre": function ( a ) {
        return a.match(/alt="(.*?)"/)[1].toLowerCase();
    },

    "alt-string-asc": function( a, b ) {
        return ((a < b) ? -1 : ((a > b) ? 1 : 0));
    },

    "alt-string-desc": function(a,b) {
        return ((a < b) ? 1 : ((a > b) ? -1 : 0));
    }
} );

$.fn.dataTableExt.oApi.fnExportData = function (oSettings, fileName)
{
	var r = this.dataTable().fnGetData();
	var l = r.length;
	var csv = '';
	var delim = ',';
	var quote = '"';
	var nl = "\n";
	var escape = function(str) {
		return quote + $('<div/>').html(str).text().replace(/[\\"']/g, '"$&') + quote + delim;
	}

	for (var i in oSettings.aoColumns)
	{
		var c = oSettings.aoColumns[i];
		csv += escape(c.sTitle);
	}
	csv += nl;

	for (var i = 0; i < l; i++)
	{
		for (var j in oSettings.aoColumns)
		{
			var c = oSettings.aoColumns[j];
			csv += escape(r[i][c.data]);
		}
		csv += nl;
	}

	var blob = new Blob([csv], {'type':'text/csv;charset=UTF-8'});
	if (typeof fileName == 'undefined')
	{
		fileName = 'export.csv';
	}
	saveAs(blob, fileName);
	return true;
}
