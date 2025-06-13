(function($) {
    'use strict';
    
    var BookingCalendar = function(element, options) {
        this.element = element;
        this.$element = $(element);
        this.options = $.extend({}, BookingCalendar.DEFAULTS, options);
        
        this.selectedStartDate = null;
        this.selectedEndDate = null;
        this.bookedDates = {};
        this.pricingData = {};
        this.currentMonthOffset = 0;
        this.maxMonthOffset = 0;
        
        this.init();
    };
    
    BookingCalendar.DEFAULTS = {
        startDay: 6, // Saturday
        monthsToShow: 12,
        monthsPerView: 2,
        minAdvanceDays: 1,
        minNights: 3,
        currencySymbol: '€',
        showForm: true
    };
    
    BookingCalendar.prototype.init = function() {
        // Calculate max offset
        this.maxMonthOffset = Math.max(0, this.options.monthsToShow - this.options.monthsPerView);
        
        this.loadCalendar();
        if (this.options.showForm) {
            this.initForm();
        }
    };
    
    BookingCalendar.prototype.loadCalendar = function() {
        var self = this;
        var today = new Date();
        var startDate = new Date(today);
        startDate.setDate(startDate.getDate() + this.options.minAdvanceDays);
        
        var endDate = new Date(startDate);
        endDate.setMonth(endDate.getMonth() + this.options.monthsToShow);
        
        // Show loading state
        this.$element.find('.br-calendar-loading').show();
        
        // Get calendar data
        $.ajax({
            url: br_calendar.ajax_url,
            type: 'POST',
            data: {
                action: 'br_get_calendar_data',
                nonce: br_calendar.nonce,
                start_date: this.formatDate(startDate),
                end_date: this.formatDate(endDate)
            },
            success: function(response) {
                if (response.success) {
                    self.pricingData = response.data.pricing_data;
                    self.bookedDates = response.data.booked_dates;
                    self.renderCalendar(startDate, endDate);
                } else {
                    self.showError(response.data || br_calendar.strings.error);
                }
            },
            error: function() {
                self.showError(br_calendar.strings.error);
            },
            complete: function() {
                self.$element.find('.br-calendar-loading').hide();
            }
        });
    };
    
    BookingCalendar.prototype.renderCalendar = function(startDate, endDate) {
        var self = this;
        var $container = this.$element.find('.br-calendar-container');
        $container.empty();
        
        // Create navigation
        var currentMonth = new Date(startDate.getFullYear(), startDate.getMonth() + this.currentMonthOffset, 1);
        var nextMonth = new Date(currentMonth);
        nextMonth.setMonth(nextMonth.getMonth() + 1);
        
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                         'July', 'August', 'September', 'October', 'November', 'December'];
        
        var $nav = $('<div class="br-calendar-nav">' +
            '<button class="br-nav-btn br-nav-prev" ' + (this.currentMonthOffset === 0 ? 'disabled' : '') + '>← Previous Month</button>' +
            '<div class="br-calendar-months-display">' + 
                monthNames[currentMonth.getMonth()] + ' - ' + monthNames[nextMonth.getMonth()] + ' ' + currentMonth.getFullYear() +
            '</div>' +
            '<button class="br-nav-btn br-nav-next" ' + (this.currentMonthOffset >= this.maxMonthOffset ? 'disabled' : '') + '>Next Month →</button>' +
        '</div>');
        
        $container.append($nav);
        
        // Calendar grid
        var $grid = $('<div class="br-calendar-grid"></div>');
        
        // Render two months
        for (var i = 0; i < this.options.monthsPerView; i++) {
            var monthToRender = new Date(startDate.getFullYear(), startDate.getMonth() + this.currentMonthOffset + i, 1);
            if (monthToRender <= endDate) {
                $grid.append(this.renderMonth(monthToRender));
            }
        }
        
        $container.append($grid);
        
        // Legend
        var $legend = $('<div class="br-calendar-legend">' +
            '<div class="br-legend-item"><div class="br-legend-color br-legend-available"></div>Available</div>' +
            '<div class="br-legend-item"><div class="br-legend-color br-legend-selected"></div>Selected</div>' +
            '<div class="br-legend-item"><div class="br-legend-color br-legend-booked"></div>Booked</div>' +
        '</div>');
        $container.append($legend);
        
        // Update selection display
        this.updateCalendarDisplay();
        
        // Event handlers
        $container.off('click').on('click', '.br-calendar-day.br-available', function() {
            self.handleDateClick($(this));
        });
        
        $container.on('click', '.br-nav-prev', function() {
            if (self.currentMonthOffset > 0) {
                self.currentMonthOffset -= self.options.monthsPerView;
                self.renderCalendar(startDate, endDate);
            }
        });
        
        $container.on('click', '.br-nav-next', function() {
            if (self.currentMonthOffset < self.maxMonthOffset) {
                self.currentMonthOffset += self.options.monthsPerView;
                self.renderCalendar(startDate, endDate);
            }
        });
    };
    
    BookingCalendar.prototype.renderMonth = function(date) {
        var month = date.getMonth();
        var year = date.getFullYear();
        var firstDay = new Date(year, month, 1);
        var lastDay = new Date(year, month + 1, 0);
        
        var $month = $('<div class="br-calendar-month"></div>');
        
        // Month header
        var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                         'July', 'August', 'September', 'October', 'November', 'December'];
        $month.append('<div class="br-calendar-month-header">' + monthNames[month] + ' ' + year + '</div>');
        
        // Weekdays
        var $weekdays = $('<div class="br-calendar-weekdays"></div>');
        var weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        
        // Start with the configured start day
        for (var i = 0; i < 7; i++) {
            var dayIndex = (this.options.startDay + i) % 7;
            $weekdays.append('<div class="br-calendar-weekday">' + weekdayNames[dayIndex] + '</div>');
        }
        $month.append($weekdays);
        
        // Days grid
        var $days = $('<div class="br-calendar-days"></div>');
        
        // Empty cells before first day
        var startingDayOfWeek = firstDay.getDay();
        var daysToSkip = (startingDayOfWeek - this.options.startDay + 7) % 7;
        for (var i = 0; i < daysToSkip; i++) {
            $days.append('<div class="br-calendar-day br-other-month"></div>');
        }
        
        // Days of month
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var minAdvanceDate = new Date(today);
        minAdvanceDate.setDate(minAdvanceDate.getDate() + this.options.minAdvanceDays);
        
        for (var day = 1; day <= lastDay.getDate(); day++) {
            var currentDate = new Date(year, month, day);
            var dateStr = this.formatDate(currentDate);
            var dayOfWeek = currentDate.getDay();
            
            var $day = $('<div class="br-calendar-day"></div>');
            $day.attr('data-date', dateStr);
            $day.append('<div class="br-day-number">' + day + '</div>');
            
            // Determine day status
            if (currentDate < minAdvanceDate) {
                $day.addClass('br-past');
            } else if (this.bookedDates[dateStr] === 'booked') {
                $day.addClass('br-booked');
            } else {
                $day.addClass('br-available');
                
                // Add price for start days (Saturday)
                if (dayOfWeek === this.options.startDay % 7 && this.pricingData[dateStr]) {
                    var price = this.pricingData[dateStr].rate;
                    if (price) {
                        $day.append('<div class="br-week-price">' + this.options.currencySymbol + Math.round(price) + '</div>');
                    }
                    
                    if (!this.pricingData[dateStr].available) {
                        $day.removeClass('br-available').addClass('br-booked');
                    }
                }
            }
            
            $days.append($day);
        }
        
        // Complete the grid
        var totalCells = $days.children().length;
        var cellsToAdd = (7 - (totalCells % 7)) % 7;
        for (var i = 0; i < cellsToAdd; i++) {
            $days.append('<div class="br-calendar-day br-other-month"></div>');
        }
        
        $month.append($days);
        
        return $month;
    };
    
    BookingCalendar.prototype.handleDateClick = function($day) {
        var clickedDate = new Date($day.attr('data-date'));
        
        // First click - set start date
        if (!this.selectedStartDate || (this.selectedStartDate && this.selectedEndDate)) {
            this.selectedStartDate = clickedDate;
            this.selectedEndDate = null;
            this.updateCalendarDisplay();
            this.clearFormDisplay();
        }
        // Second click - set end date
        else if (this.selectedStartDate && !this.selectedEndDate) {
            // Ensure end date is after start date
            if (clickedDate <= this.selectedStartDate) {
                // Reset selection
                this.selectedStartDate = clickedDate;
                this.selectedEndDate = null;
                this.updateCalendarDisplay();
                this.clearFormDisplay();
                return;
            }
            
            // Check minimum nights
            var nights = Math.floor((clickedDate - this.selectedStartDate) / (1000 * 60 * 60 * 24));
            if (nights < this.options.minNights) {
                this.showError(br_calendar.strings.min_nights_error || 'Minimum stay is ' + this.options.minNights + ' nights');
                return;
            }
            
            // Check availability of all dates in range
            var tempDate = new Date(this.selectedStartDate);
            var allAvailable = true;
            
            while (tempDate <= clickedDate) {
                var dateStr = this.formatDate(tempDate);
                if (this.bookedDates[dateStr] === 'booked' || 
                    this.$element.find('.br-calendar-day[data-date="' + dateStr + '"]').hasClass('br-booked')) {
                    allAvailable = false;
                    break;
                }
                tempDate.setDate(tempDate.getDate() + 1);
            }
            
            if (!allAvailable) {
                this.showError(br_calendar.strings.dates_unavailable || 'Some dates in this range are not available');
                this.selectedStartDate = null;
                this.selectedEndDate = null;
                this.updateCalendarDisplay();
                return;
            }
            
            // Set end date
            this.selectedEndDate = clickedDate;
            this.updateCalendarDisplay();
            this.updateFormDates();
            this.calculatePrice();
        }
    };
    
    BookingCalendar.prototype.updateCalendarDisplay = function() {
        var self = this;
        
        // Clear all selection classes
        this.$element.find('.br-calendar-day').removeClass('br-selected br-in-range');
        
        if (this.selectedStartDate) {
            // Highlight start date
            var startDateStr = this.formatDate(this.selectedStartDate);
            this.$element.find('.br-calendar-day[data-date="' + startDateStr + '"]').addClass('br-selected');
            
            if (this.selectedEndDate) {
                // Highlight end date
                var endDateStr = this.formatDate(this.selectedEndDate);
                this.$element.find('.br-calendar-day[data-date="' + endDateStr + '"]').addClass('br-selected');
                
                // Highlight range
                this.$element.find('.br-calendar-day').each(function() {
                    var $day = $(this);
                    var dateStr = $day.attr('data-date');
                    if (dateStr) {
                        var date = new Date(dateStr);
                        if (date > self.selectedStartDate && date < self.selectedEndDate) {
                            $day.addClass('br-in-range');
                        }
                    }
                });
            }
        }
    };
    
    BookingCalendar.prototype.clearFormDisplay = function() {
        $('.br-selected-dates').removeClass('show');
        $('.br-price-display').removeClass('show');
        $('.br-submit-btn').prop('disabled', true);
    };
    
    BookingCalendar.prototype.initForm = function() {
        if (!this.options.showForm) return;
        
        var self = this;
        var $formContainer = this.$element.find('.br-calendar-form-container');
        
        var services = [
            { key: 'taxi_service', label: 'Taxi service from and to the airport' },
            { key: 'greeting_service', label: 'Greeting service – to welcome you to the Villa and overview the region' },
            { key: 'welcome_hamper', label: 'Welcome hamper on arrival' },
            { key: 'sailing', label: 'Sailing' },
            { key: 'motorboat_hire', label: 'Motorboat hire' },
            { key: 'flight_bookings', label: 'Flight bookings (from the UK)' },
            { key: 'car_hire', label: 'Car hire booking service' }
        ];
        
        var servicesHtml = services.map(function(service) {
            return '<div class="br-service-item">' +
                '<input type="checkbox" id="service_' + service.key + '" name="additional_services[]" value="' + service.key + '" class="br-service-checkbox">' +
                '<label for="service_' + service.key + '" class="br-service-label">' + service.label + '</label>' +
                '</div>';
        }).join('');
        
        var formHtml = 
            '<div class="br-booking-form">' +
                '<h3 class="br-form-title">Booking Request Form</h3>' +
                '<p class="br-form-subtitle">Please select your dates above and fill in the form below</p>' +
                
                '<div class="br-selected-dates">' +
                    '<div class="br-dates-title">Selected Dates</div>' +
                    '<div class="br-dates-info"></div>' +
                '</div>' +
                
                '<div class="br-price-display">' +
                    '<div class="br-price-label">Total Price</div>' +
                    '<div class="br-price-amount"></div>' +
                '</div>' +
                
                '<form class="br-form" method="post">' +
                    '<input type="hidden" name="checkin_date" id="br-checkin-date">' +
                    '<input type="hidden" name="checkout_date" id="br-checkout-date">' +
                    '<input type="hidden" name="total_price" id="br-total-price">' +
                    
                    '<div class="br-form-group">' +
                        '<label for="br-guest-name" class="br-form-label">Full Name <span class="required">*</span></label>' +
                        '<input type="text" name="guest_name" id="br-guest-name" class="br-form-control" required>' +
                    '</div>' +
                    
                    '<div class="br-form-group">' +
                        '<label for="br-email" class="br-form-label">Email Address <span class="required">*</span></label>' +
                        '<input type="email" name="email" id="br-email" class="br-form-control" required>' +
                    '</div>' +
                    
                    '<div class="br-form-group">' +
                        '<label for="br-phone" class="br-form-label">Phone Number <span class="required">*</span></label>' +
                        '<input type="tel" name="phone" id="br-phone" class="br-form-control" required>' +
                    '</div>' +
                    
                    '<div class="br-form-group">' +
                        '<label class="br-form-label">Additional Services</label>' +
                        '<div class="br-services-group">' + servicesHtml + '</div>' +
                    '</div>' +
                    
                    '<div class="br-form-group">' +
                        '<label for="br-message" class="br-form-label">Special Requests or Comments</label>' +
                        '<textarea name="message" id="br-message" class="br-form-control" rows="4" placeholder="Please let us know if you have any special requirements or questions..."></textarea>' +
                    '</div>' +
                    
                    '<button type="submit" class="br-submit-btn" disabled>Submit Booking Request</button>' +
                '</form>' +
            '</div>';
        
        $formContainer.html(formHtml);
        
        // Form submission
        $formContainer.on('submit', '.br-form', function(e) {
            e.preventDefault();
            self.submitBooking();
        });
    };
    
    BookingCalendar.prototype.updateFormDates = function() {
        if (!this.selectedStartDate || !this.selectedEndDate) return;
        
        var checkinStr = this.formatDate(this.selectedStartDate);
        var checkoutStr = this.formatDate(this.selectedEndDate);
        
        $('#br-checkin-date').val(checkinStr);
        $('#br-checkout-date').val(checkoutStr);
        
        // Calculate nights
        var nights = Math.floor((this.selectedEndDate - this.selectedStartDate) / (1000 * 60 * 60 * 24));
        
        // Format dates nicely
        var options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
        var checkinFormatted = this.selectedStartDate.toLocaleDateString('en-US', options);
        var checkoutFormatted = this.selectedEndDate.toLocaleDateString('en-US', options);
        
        $('.br-selected-dates').addClass('show');
        $('.br-dates-info').text(checkinFormatted + ' - ' + checkoutFormatted + ' (' + nights + ' nights)');
        
        $('.br-submit-btn').prop('disabled', false);
    };
    
    BookingCalendar.prototype.calculatePrice = function() {
        if (!this.selectedStartDate || !this.selectedEndDate) return;
        
        var self = this;
        
        $.ajax({
            url: br_calendar.ajax_url,
            type: 'POST',
            data: {
                action: 'br_calculate_price',
                nonce: br_calendar.nonce,
                checkin: this.formatDate(this.selectedStartDate),
                checkout: this.formatDate(this.selectedEndDate)
            },
            success: function(response) {
                if (response.success) {
                    $('#br-total-price').val(response.data.total_price);
                    $('.br-price-display').addClass('show');
                    $('.br-price-amount').text(response.data.formatted_total);
                }
            }
        });
    };
    
    BookingCalendar.prototype.submitBooking = function() {
        var self = this;
        var $form = this.$element.find('.br-form');
        var $submitBtn = $form.find('.br-submit-btn');
        
        if (!$form[0].checkValidity()) {
            $form[0].reportValidity();
            return;
        }
        
        // Show loading
        $submitBtn.prop('disabled', true).html('Processing...<span class="br-spinner"></span>');
        
        var formData = {
            action: 'br_submit_calendar_booking',
            nonce: br_calendar.nonce,
            guest_name: $('#br-guest-name').val(),
            email: $('#br-email').val(),
            phone: $('#br-phone').val(),
            checkin_date: $('#br-checkin-date').val(),
            checkout_date: $('#br-checkout-date').val(),
            total_price: $('#br-total-price').val(),
            message: $('#br-message').val(),
            additional_services: []
        };
        
        // Get selected services
        $('input[name="additional_services[]"]:checked').each(function() {
            formData.additional_services.push($(this).val());
        });
        
        $.ajax({
            url: br_calendar.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    self.showSuccess(response.data.message || 'Your booking request has been submitted successfully!');
                    $form[0].reset();
                    self.selectedStartDate = null;
                    self.selectedEndDate = null;
                    self.updateCalendarDisplay();
                    self.clearFormDisplay();
                    
                    // Reload calendar
                    setTimeout(function() {
                        self.loadCalendar();
                    }, 2000);
                } else {
                    self.showError(response.data || 'An error occurred. Please try again.');
                }
            },
            error: function() {
                self.showError('An error occurred. Please try again.');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text('Submit Booking Request');
            }
        });
    };
    
    BookingCalendar.prototype.formatDate = function(date) {
        var year = date.getFullYear();
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        var day = ('0' + date.getDate()).slice(-2);
        return year + '-' + month + '-' + day;
    };
    
    BookingCalendar.prototype.showError = function(message) {
        this.showMessage(message, 'error');
    };
    
    BookingCalendar.prototype.showSuccess = function(message) {
        this.showMessage(message, 'success');
    };
    
    BookingCalendar.prototype.showMessage = function(message, type) {
        // Remove existing messages
        this.$element.find('.br-message').remove();
        
        var $message = $('<div class="br-message br-message-' + type + '">' + message + '</div>');
        
        if (this.options.showForm) {
            this.$element.find('.br-booking-form').prepend($message);
        } else {
            this.$element.find('.br-calendar-container').prepend($message);
        }
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $message.offset().top - 100
        }, 500);
        
        // Auto remove
        setTimeout(function() {
            $message.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    };
    
    // jQuery plugin
    $.fn.bookingCalendar = function(options) {
        return this.each(function() {
            var $this = $(this);
            var data = $this.data('booking-calendar');
            
            if (!data) {
                var opts = $.extend({}, $this.data(), options);
                $this.data('booking-calendar', new BookingCalendar(this, opts));
            }
        });
    };
    
    // Auto-initialize
    $(document).ready(function() {
        $('.br-calendar-widget').each(function() {
            $(this).bookingCalendar();
        });
    });
    
})(jQuery);