/* global wpDataBridge, jQuery */
( function ( $ ) {
	'use strict';

	const WPDataBridge = {
		init() {
			this.bindEvents();
			this.loadInitialData();
		},

		bindEvents() {
			$( '#site_id' ).on( 'change', this.onSiteChange.bind( this ) );
			$( '#wp-data-bridge-form' ).on(
				'submit',
				this.onFormSubmit.bind( this )
			);
			$( '#export_posts' ).on(
				'change',
				this.togglePostTypesRow.bind( this )
			);
			$( '#date_start, #date_end' ).on(
				'change',
				this.validateDateRange.bind( this )
			);
		},

		loadInitialData() {
			const siteId = $( '#site_id' ).val();
			if ( siteId ) {
				this.loadSiteData( siteId );
			} else {
				this.loadSiteData( 1 );
			}
		},

		onSiteChange( e ) {
			const siteId = $( e.target ).val();
			if ( siteId ) {
				this.loadSiteData( siteId );
			}
		},

		loadSiteData( siteId ) {
			this.showLoading( '#site-statistics' );
			this.showLoading( '#post-types-container' );

			$.ajax( {
				url: wpDataBridge.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_data_bridge_get_site_stats',
					site_id: siteId,
					nonce: wpDataBridge.nonce,
				},
				success: ( response ) => {
					if ( response.success ) {
						this.updateSiteStatistics( response.data.stats );
						this.updatePostTypes( response.data.post_types );
						$( '#site-statistics' ).fadeIn();
					} else {
						this.showError(
							response.data || 'Failed to load site data'
						);
					}
				},
				error: () => {
					this.showError( 'Network error while loading site data' );
				},
				complete: () => {
					this.hideLoading( '#site-statistics' );
					this.hideLoading( '#post-types-container' );
				},
			} );
		},

		updateSiteStatistics( stats ) {
			$( '#posts-count' ).text( this.formatNumber( stats.posts ) );
			$( '#pages-count' ).text( this.formatNumber( stats.pages ) );
			$( '#users-count' ).text( this.formatNumber( stats.users ) );
			$( '#attachments-count' ).text(
				this.formatNumber( stats.attachments )
			);
		},

		updatePostTypes( postTypes ) {
			const container = $( '#post-types-container' );
			const html = postTypes
				.map(
					( postType ) => `
                <div class="post-type-item">
                    <label class="post-type-label">
                        <input type="checkbox" name="post_types[]" value="${
							postType.name
						}" 
                               ${
									this.isDefaultPostType( postType.name )
										? 'checked'
										: ''
								}>
                        ${ postType.label }
                        <span class="post-type-count">${ this.formatNumber(
							postType.count
						) }</span>
                    </label>
                </div>
            `
				)
				.join( '' );

			container.html( html );
		},

		isDefaultPostType( name ) {
			return [ 'post', 'page' ].includes( name );
		},

		togglePostTypesRow() {
			const isChecked = $( '#export_posts' ).is( ':checked' );
			$( '#post-types-row' ).toggle( isChecked );
		},

		validateDateRange() {
			const startDate = $( '#date_start' ).val();
			const endDate = $( '#date_end' ).val();
			const siteId = $( '#site_id' ).val();

			if ( startDate && endDate && siteId ) {
				const selectedPostTypes = $(
					'input[name="post_types[]"]:checked'
				)
					.map( function () {
						return this.value;
					} )
					.get();

				if ( selectedPostTypes.length > 0 ) {
					$.ajax( {
						url: wpDataBridge.ajaxUrl,
						type: 'POST',
						data: {
							action: 'wp_data_bridge_validate_date_range',
							site_id: siteId,
							post_types: selectedPostTypes,
							date_start: startDate,
							date_end: endDate,
							nonce: wpDataBridge.nonce,
						},
						success: ( response ) => {
							if ( response.success && ! response.data.valid ) {
								this.showNotice(
									response.data.message,
									'warning'
								);
							}
						},
					} );
				}
			}
		},

		onFormSubmit( e ) {
			e.preventDefault();

			if ( ! this.validateForm() ) {
				return;
			}

			this.startExport();
		},

		validateForm() {
			const siteId = $( '#site_id' ).val();

			if ( ! siteId ) {
				this.showError( 'Please select a site.' );
				return false;
			}

			const exportTypes = $( 'input[name="export_types[]"]:checked' );
			if ( exportTypes.length === 0 ) {
				this.showError( 'Please select at least one export type.' );
				return false;
			}

			if ( $( '#export_posts' ).is( ':checked' ) ) {
				const postTypes = $( 'input[name="post_types[]"]:checked' );
				if ( postTypes.length === 0 ) {
					this.showError( 'Please select at least one post type.' );
					return false;
				}
			}

			return true;
		},

		startExport() {
			const formData = this.getFormData();

			this.showProgress();
			this.setProgress( 0, wpDataBridge.strings.exporting );
			$( '#start-export-btn' ).prop( 'disabled', true );
			$( '#export-spinner' ).addClass( 'is-active' );

			$.ajax( {
				url: wpDataBridge.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_data_bridge_export',
					...formData,
					nonce: wpDataBridge.nonce,
				},
				success: ( response ) => {
					if ( response.success ) {
						this.handleExportSuccess( response.data );
					} else {
						this.handleExportError(
							response.data || 'Export failed'
						);
					}
				},
				error: ( xhr ) => {
					const message =
						xhr.responseJSON && xhr.responseJSON.data
							? xhr.responseJSON.data
							: 'Network error during export';
					this.handleExportError( message );
				},
				complete: () => {
					$( '#start-export-btn' ).prop( 'disabled', false );
					$( '#export-spinner' ).removeClass( 'is-active' );
				},
			} );
		},

		getFormData() {
			const data = {};

			data.site_id = $( '#site_id' ).val();
			data.export_types = $( 'input[name="export_types[]"]:checked' )
				.map( function () {
					return this.value;
				} )
				.get();
			data.post_types = $( 'input[name="post_types[]"]:checked' )
				.map( function () {
					return this.value;
				} )
				.get();
			data.date_start = $( '#date_start' ).val();
			data.date_end = $( '#date_end' ).val();

			return data;
		},

		handleExportSuccess( data ) {
			this.setProgress( 100, wpDataBridge.strings.complete );

			setTimeout( () => {
				this.hideProgress();
				this.showResults( data.files );
				this.showNotice( data.message, 'success' );
			}, 1000 );
		},

		handleExportError( message ) {
			this.hideProgress();
			this.showError( message );
		},

		showProgress() {
			$( '#export-progress' ).show().addClass( 'fadeIn' );
		},

		hideProgress() {
			$( '#export-progress' ).hide().removeClass( 'fadeIn' );
		},

		setProgress( percentage, text ) {
			$( '#progress-fill' ).css( 'width', percentage + '%' );
			$( '#progress-text' ).text( text );
		},

		showResults( files ) {
			const container = $( '#download-links' );
			const html = files
				.map(
					( file ) => `
                <div class="download-item">
                    <div class="download-info">
                        <div class="download-title">${ this.getFileTypeLabel(
							file.type
						) } Export</div>
                        <div class="download-meta">
                            ${ this.formatNumber( file.records ) } records • ${
								file.size
							}
                        </div>
                    </div>
                    <div class="download-actions">
                        <a href="${
							file.url
						}" class="download-btn" download="${ file.filename }">
                            Download CSV
                        </a>
                    </div>
                </div>
            `
				)
				.join( '' );

			container.html( html );
			$( '#export-results' ).show().addClass( 'fadeIn' );
		},

		getFileTypeLabel( type ) {
			const labels = {
				posts: 'Posts & Pages',
				users: 'Users',
				images: 'Featured Images',
			};
			return labels[ type ] || type;
		},

		showError( message ) {
			$( '#error-message' ).text( message );
			$( '#export-error' ).show();

			setTimeout( () => {
				$( '#export-error' ).fadeOut();
			}, 8000 );
		},

		showNotice( message, type = 'info' ) {
			let noticeClass = 'notice-info';
			if ( type === 'success' ) {
				noticeClass = 'notice-success';
			} else if ( type === 'warning' ) {
				noticeClass = 'notice-warning';
			}

			const notice = $( `
                <div class="notice ${ noticeClass } wp-data-bridge-notice is-dismissible">
                    <p>${ message }</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            ` );

			$( '.wrap h1' ).after( notice );

			notice.on( 'click', '.notice-dismiss', function () {
				notice.fadeOut();
			} );
		},

		showLoading( selector ) {
			$( selector ).addClass( 'loading' );
		},

		hideLoading( selector ) {
			$( selector ).removeClass( 'loading' );
		},

		formatNumber( num ) {
			return new Intl.NumberFormat().format( num );
		},
	};

	$( document ).ready( () => {
		WPDataBridge.init();
	} );
} )( jQuery );
