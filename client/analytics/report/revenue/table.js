/** @format */
/**
 * External dependencies
 */
import { __, _n } from '@wordpress/i18n';
import { Component } from '@wordpress/element';
import { format as formatDate } from '@wordpress/date';
import { compose } from '@wordpress/compose';
import { get } from 'lodash';

/**
 * WooCommerce dependencies
 */
import { appendTimestamp, defaultTableDateFormat, getCurrentDates } from 'lib/date';
import { Date, Link } from '@woocommerce/components';
import { formatCurrency, getCurrencyFormatDecimal, renderCurrency } from 'lib/currency-format';
import { formatValue } from 'lib/number-format';

/**
 * Internal dependencies
 */
import { QUERY_DEFAULTS } from 'wc-api/constants';
import ReportTable from 'analytics/components/report-table';
import withSelect from 'wc-api/with-select';

class RevenueReportTable extends Component {
	constructor() {
		super();

		this.getHeadersContent = this.getHeadersContent.bind( this );
		this.getRowsContent = this.getRowsContent.bind( this );
		this.getSummary = this.getSummary.bind( this );
	}

	getHeadersContent() {
		return [
			{
				label: __( 'Date', 'woocommerce-admin' ),
				key: 'date',
				required: true,
				defaultSort: true,
				isLeftAligned: true,
				isSortable: true,
			},
			{
				label: __( 'Orders', 'woocommerce-admin' ),
				key: 'orders_count',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Gross Sales', 'woocommerce-admin' ),
				key: 'gross_sales',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Returns', 'woocommerce-admin' ),
				key: 'refunds',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Coupons', 'woocommerce-admin' ),
				key: 'coupons',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Net Sales', 'woocommerce-admin' ),
				key: 'net_revenue',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Taxes', 'woocommerce-admin' ),
				key: 'taxes',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Shipping', 'woocommerce-admin' ),
				key: 'shipping',
				required: false,
				isSortable: true,
				isNumeric: true,
			},
			{
				label: __( 'Total Sales', 'woocommerce-admin' ),
				key: 'total_sales',
				required: true,
				isSortable: true,
				isNumeric: true,
			},
		];
	}

	getRowsContent( data = [] ) {
		return data.map( row => {
			const {
				coupons,
				gross_sales,
				total_sales,
				net_revenue,
				orders_count,
				refunds,
				shipping,
				taxes,
			} = row.subtotals;

			// @todo How to create this per-report? Can use `w`, `year`, `m` to build time-specific order links
			// we need to know which kind of report this is, and parse the `label` to get this row's date
			const orderLink = (
				<Link
					href={ 'edit.php?post_type=shop_order&m=' + formatDate( 'Ymd', row.date_start ) }
					type="wp-admin"
				>
					{ formatValue( 'number', orders_count ) }
				</Link>
			);
			return [
				{
					display: <Date date={ row.date_start } visibleFormat={ defaultTableDateFormat } />,
					value: row.date_start,
				},
				{
					display: orderLink,
					value: Number( orders_count ),
				},
				{
					display: renderCurrency( gross_sales ),
					value: getCurrencyFormatDecimal( gross_sales ),
				},
				{
					display: formatCurrency( refunds ),
					value: getCurrencyFormatDecimal( refunds ),
				},
				{
					display: formatCurrency( coupons ),
					value: getCurrencyFormatDecimal( coupons ),
				},
				{
					display: renderCurrency( net_revenue ),
					value: getCurrencyFormatDecimal( net_revenue ),
				},
				{
					display: renderCurrency( taxes ),
					value: getCurrencyFormatDecimal( taxes ),
				},
				{
					display: renderCurrency( shipping ),
					value: getCurrencyFormatDecimal( shipping ),
				},
				{
					display: renderCurrency( total_sales ),
					value: getCurrencyFormatDecimal( total_sales ),
				},
			];
		} );
	}

	getSummary( totals, totalResults = 0 ) {
		const {
			orders_count = 0,
			gross_sales = 0,
			total_sales = 0,
			refunds = 0,
			coupons = 0,
			taxes = 0,
			shipping = 0,
			net_revenue = 0,
		} = totals;
		return [
			{
				label: _n( 'day', 'days', totalResults, 'woocommerce-admin' ),
				value: formatValue( 'number', totalResults ),
			},
			{
				label: _n( 'order', 'orders', orders_count, 'woocommerce-admin' ),
				value: formatValue( 'number', orders_count ),
			},
			{
				label: __( 'gross sales', 'woocommerce-admin' ),
				value: formatCurrency( gross_sales ),
			},
			{
				label: __( 'returns', 'woocommerce-admin' ),
				value: formatCurrency( refunds ),
			},
			{
				label: __( 'coupons', 'woocommerce-admin' ),
				value: formatCurrency( coupons ),
			},
			{
				label: __( 'net sales', 'woocommerce-admin' ),
				value: formatCurrency( net_revenue ),
			},
			{
				label: __( 'taxes', 'woocommerce-admin' ),
				value: formatCurrency( taxes ),
			},
			{
				label: __( 'shipping', 'woocommerce-admin' ),
				value: formatCurrency( shipping ),
			},
			{
				label: __( 'total sales', 'woocommerce-admin' ),
				value: formatCurrency( total_sales ),
			},
		];
	}

	render() {
		const { advancedFilters, filters, tableData, query } = this.props;

		return (
			<ReportTable
				endpoint="revenue"
				getHeadersContent={ this.getHeadersContent }
				getRowsContent={ this.getRowsContent }
				getSummary={ this.getSummary }
				query={ query }
				tableData={ tableData }
				title={ __( 'Revenue', 'woocommerce-admin' ) }
				columnPrefsKey="revenue_report_columns"
				filters={ filters }
				advancedFilters={ advancedFilters }
			/>
		);
	}
}

export default compose(
	withSelect( ( select, props ) => {
		const { query } = props;
		const datesFromQuery = getCurrentDates( query );
		const { getReportStats, getReportStatsError, isReportStatsRequesting } = select( 'wc-api' );

		// @todo Support hour here when viewing a single day
		const tableQuery = {
			interval: 'day',
			orderby: query.orderby || 'date',
			order: query.order || 'desc',
			page: query.paged || 1,
			per_page: query.per_page || QUERY_DEFAULTS.pageSize,
			after: appendTimestamp( datesFromQuery.primary.after, 'start' ),
			before: appendTimestamp( datesFromQuery.primary.before, 'end' ),
		};
		const revenueData = getReportStats( 'revenue', tableQuery );
		const isError = Boolean( getReportStatsError( 'revenue', tableQuery ) );
		const isRequesting = isReportStatsRequesting( 'revenue', tableQuery );

		return {
			tableData: {
				items: {
					data: get( revenueData, [ 'data', 'intervals' ], [] ),
					totalResults: get( revenueData, [ 'totalResults' ], 0 ),
				},
				isError,
				isRequesting,
				query: tableQuery,
			},
		};
	} )
)( RevenueReportTable );
