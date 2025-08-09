/* global wpDataBridge, jQuery */
( function ( $ ) {
	'use strict';

	const WPDataBridge = {
		init() {
			this.bindEvents();
			this.loadInitialData();
		},

		bindEvents() {
			// Tab switching
			$( '.nav-tab' ).on( 'click', this.onTabClick.bind( this ) );
			
			// Export events
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
			
			// Import events
			$( '#csv_file' ).on( 'change', this.onFileSelect.bind( this ) );
			$( '#preview-import-btn' ).on( 'click', this.onPreviewImport.bind( this ) );
			$( '#start-import-btn' ).on( 'click', this.onStartImport.bind( this ) );
			
			// File drag and drop
			$( '#upload-area' ).on( {
				'dragover dragenter': function(e) {
					e.preventDefault();
					e.stopPropagation();
					$(this).addClass('dragover');
				},
				'dragleave dragend drop': function(e) {
					e.preventDefault();
					e.stopPropagation();
					$(this).removeClass('dragover');
				},
				'drop': this.onFileDrop.bind(this)
			});
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
		
		// Tab Management
		onTabClick( e ) {
			e.preventDefault();
			const targetTab = $( e.target ).attr( 'href' ).substring( 1 );
			this.switchTab( targetTab );
		},
		
		switchTab( tabName ) {
			// Update nav tabs
			$( '.nav-tab' ).removeClass( 'nav-tab-active' );
			$( '#' + tabName + '-tab' ).addClass( 'nav-tab-active' );
			
			// Update tab content
			$( '.tab-content' ).hide().removeClass( 'active' );
			$( '#' + tabName + '-content' ).show().addClass( 'active' );
		},
		
		// Import Functions
		onFileSelect( e ) {
			const file = e.target.files[0];
			if ( file ) {
				this.handleFileSelection( file );
			}
		},
		
		onFileDrop( e ) {
			const files = e.originalEvent.dataTransfer.files;
			if ( files.length > 0 ) {
				const file = files[0];
				if ( file.type === 'text/csv' || file.name.endsWith('.csv') ) {
					$( '#csv_file' )[0].files = files;
					this.handleFileSelection( file );
				} else {
					this.showImportError( 'Please select a CSV file.' );
				}
			}
		},
		
		handleFileSelection( file ) {
			$( '#upload-area .upload-instructions p' ).first().html(
				'<strong>Selected: ' + file.name + '</strong> (' + this.formatFileSize( file.size ) + ')'
			);
			$( '#preview-import-btn' ).prop( 'disabled', false );
		},
		
		onPreviewImport( e ) {
			e.preventDefault();
			
			// Validate file is selected
			const csvFile = $( '#csv_file' )[0].files[0];
			if ( ! csvFile ) {
				this.showImportError( 'Please select a CSV file first.' );
				return;
			}
			
			const formData = new FormData( $( '#wp-data-bridge-import-form' )[0] );
			formData.append( 'action', 'wp_data_bridge_import_preview' );
			
			// Get the import nonce from the form
			const importNonce = $( '#wp_data_bridge_import_nonce' ).val();
			if ( ! importNonce ) {
				this.showImportError( 'Security nonce missing. Please refresh the page.' );
				return;
			}
			formData.append( 'nonce', importNonce );
			
			// Debug: Log FormData contents
			console.log('FormData contents:', {
				hasFile: formData.has('csv_file'),
				hasNonce: formData.has('nonce'),
				hasAction: formData.has('action'),
				fileName: csvFile.name,
				fileSize: csvFile.size
			});
			
			$( '#import-spinner' ).addClass( 'is-active' );
			$( '#preview-import-btn' ).prop( 'disabled', true );
			
			$.ajax( {
				url: wpDataBridge.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: ( response ) => {
					if ( response.success ) {
						this.showImportPreview( response.data );
						$( '#start-import-btn' ).prop( 'disabled', false );
					} else {
						this.showImportError( response.data || 'Preview failed' );
					}
				},
				error: ( xhr, status, error ) => {
					console.error('Preview AJAX Error:', {
						status: xhr.status,
						statusText: xhr.statusText,
						responseText: xhr.responseText,
						error: error
					});
					let errorMessage = 'Network error during preview';
					if ( xhr.responseText ) {
						try {
							const response = JSON.parse( xhr.responseText );
							if ( response.data ) {
								errorMessage += ': ' + response.data;
							}
						} catch ( e ) {
							errorMessage += ' (Status: ' + xhr.status + ')';
						}
					}
					this.showImportError( errorMessage );
				},
				complete: () => {
					$( '#import-spinner' ).removeClass( 'is-active' );
					$( '#preview-import-btn' ).prop( 'disabled', false );
				}
			} );
		},
		
		onStartImport( e ) {
			e.preventDefault();
			
			if ( ! this.validateImportForm() ) {
				return;
			}
			
			const formData = new FormData( $( '#wp-data-bridge-import-form' )[0] );
			formData.append( 'action', 'wp_data_bridge_import' );
			// Get the import nonce from the form  
			const importNonce = $( '#wp_data_bridge_import_nonce' ).val();
			formData.append( 'nonce', importNonce );
			
			this.showImportProgress();
			this.setImportProgress( 0, 'Preparing import...' );
			$( '#start-import-btn' ).prop( 'disabled', true );
			$( '#import-spinner' ).addClass( 'is-active' );
			
			$.ajax( {
				url: wpDataBridge.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: ( response ) => {
					if ( response.success ) {
						this.handleImportSuccess( response.data );
					} else {
						this.handleImportError( response.data || 'Import failed' );
					}
				},
				error: ( xhr ) => {
					const message = xhr.responseJSON && xhr.responseJSON.data
						? xhr.responseJSON.data
						: 'Network error during import';
					this.handleImportError( message );
				},
				complete: () => {
					$( '#start-import-btn' ).prop( 'disabled', false );
					$( '#import-spinner' ).removeClass( 'is-active' );
				}
			} );
		},
		
		validateImportForm() {
			const targetSiteId = $( '#import_target_site_id' ).val();
			if ( ! targetSiteId ) {
				this.showImportError( 'Please select a target site.' );
				return false;
			}
			
			const csvFile = $( '#csv_file' )[0].files[0];
			if ( ! csvFile ) {
				this.showImportError( 'Please select a CSV file to import.' );
				return false;
			}
			
			return true;
		},
		
		showImportPreview( data ) {
			const container = $( '#preview-content' );
			let html = `
				<div class="import-preview-summary">
					<p><strong>Headers:</strong> ${ data.headers.join( ', ' ) }</p>
					<p><strong>Total Rows:</strong> ${ this.formatNumber( data.total_rows ) }</p>
				</div>
			`;
			
			if ( data.preview_rows && data.preview_rows.length > 0 ) {
				html += '<div class="import-preview-table">';
				html += '<table class="widefat striped">';
				html += '<thead><tr>';
				data.headers.forEach( header => {
					html += '<th>' + header + '</th>';
				});
				html += '</tr></thead><tbody>';
				
				data.preview_rows.forEach( row => {
					html += '<tr>';
					row.forEach( cell => {
						const cellContent = cell ? String(cell).substring(0, 50) + (cell.length > 50 ? '...' : '') : '';
						html += '<td>' + cellContent + '</td>';
					});
					html += '</tr>';
				});
				
				html += '</tbody></table></div>';
			}
			
			container.html( html );
			$( '#import-preview' ).show();
		},
		
		showImportProgress() {
			$( '#import-progress' ).show().addClass( 'fadeIn' );
		},
		
		setImportProgress( percentage, text ) {
			$( '#import-progress-fill' ).css( 'width', percentage + '%' );
			$( '#import-progress-text' ).text( text );
		},
		
		handleImportSuccess( data ) {
			this.setImportProgress( 100, 'Import complete!' );
			
			setTimeout( () => {
				$( '#import-progress' ).hide();
				this.showImportResults( data.results );
				this.showNotice( data.message, 'success' );
			}, 1000 );
		},
		
		handleImportError( message ) {
			$( '#import-progress' ).hide();
			this.showImportError( message );
		},
		
		showImportResults( results ) {
			const container = $( '#import-summary' );
			const html = `
				<div class="import-results-summary">
					<div class="import-stat success">
						<span class="stat-number">${ this.formatNumber( results.success ) }</span>
						<span class="stat-label">Successful</span>
					</div>
					<div class="import-stat skipped">
						<span class="stat-number">${ this.formatNumber( results.skipped ) }</span>
						<span class="stat-label">Skipped</span>
					</div>
					<div class="import-stat error">
						<span class="stat-number">${ this.formatNumber( results.errors ) }</span>
						<span class="stat-label">Errors</span>
					</div>
				</div>
				${ results.messages && results.messages.length > 0 ? 
					'<div class="import-messages"><h4>Messages:</h4><ul>' + 
					results.messages.map( msg => '<li>' + msg + '</li>' ).join('') + 
					'</ul></div>' : '' 
				}
				${ results.error_details && results.error_details.length > 0 ? 
					'<div class="import-errors"><h4>Errors:</h4><ul>' + 
					results.error_details.map( error => '<li>' + error + '</li>' ).join('') + 
					'</ul></div>' : '' 
				}
			`;
			
			container.html( html );
			$( '#import-results' ).show().addClass( 'fadeIn' );
		},
		
		showImportError( message ) {
			$( '#import-error-message' ).text( message );
			$( '#import-error' ).show();
			
			setTimeout( () => {
				$( '#import-error' ).fadeOut();
			}, 8000 );
		},
		
		formatFileSize( bytes ) {
			if ( bytes === 0 ) return '0 Bytes';
			const k = 1024;
			const sizes = ['Bytes', 'KB', 'MB', 'GB'];
			const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
			return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[i];
		},
	};

	$( document ).ready( () => {
		WPDataBridge.init();
	} );
} )( jQuery );
