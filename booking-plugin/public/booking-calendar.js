jQuery(document).ready(function($) {
    'use strict';
    
    // Calendar widget class
    class BookingCalendar {
        constructor(container) {
            this.container = $(container);
            this.monthsToShow = this.container.data('months') || 12;
            this.startDay = this.container.data('start-day') || 'saturday';
            this.selectedWeek = null;
            this.calendarData = null;
            
            this.init();
        }
        
        init() {
            this.loadCalendarData();
            this.bindEvents();
        }
        
        loadCalendarData() {
            const self = this;
            const startDate = new Date();
            const endDate = new Date();
            endDate.setMonth(endDate.getMonth() + this.monthsToShow);
            
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
                        self.calendarData = response.data;
                        self.renderCalendar();
                    }
                },
                error: function() {
                    self.showError(br_calendar.strings.error);
                }
            });
        }
        
        renderCalendar() {
            const monthsContainer = this.container.find('.br-calendar-months');
            monthsContainer.empty();
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            for (let i = 0; i < this.monthsToShow; i++) {
                const monthDate = new Date(today.getFullYear(), today.getMonth() + i, 1);
                monthsContainer.append(this.renderMonth(monthDate));
            }
            
            this.container.find('.br-calendar-loading').hide();
            monthsContainer.show();
        }
        
        renderMonth(date) {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            
            const year = date.getFullYear();
            const month = date.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
            let html = `
                <div class="br-calendar-month">
                    <div class="br-calendar-month-header">
                        <h4>${monthNames[month]} ${year}</h4>
                    </div>
                    <table class="br-calendar-table">
                        <thead>
                            <tr>
            `;
            
            // Day headers
            for (let day of dayNames) {
                html += `<th>${day}</th>`;
            }
            html += '</tr></thead><tbody><tr>';
            
            // Empty cells before first day
            for (let i = 0; i < firstDay; i++) {
                html += '<td></td>';
            }
            
            // Days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const currentDate = new Date(year, month, day);
                const dayOfWeek = currentDate.getDay();
                const dateStr = this.formatDate(currentDate);
                
                // Check if this is a bookable start day (e.g., Saturday = 6)
                const isStartDay = dayOfWeek === this.calendarData.start_day;
                const weekData = this.getWeekData(dateStr);
                
                let classes = ['br-calendar-day'];
                let content = day;
                
                if (isStartDay && weekData) {
                    classes.push('br-week-start');
                    
                    if (weekData.available) {
                        classes.push('br-available');
                        content = `
                            <span class="br-day-number">${day}</span>
                            <span class="br-day-price">${br_calendar.currency_symbol}${weekData.rate.toLocaleString()}</span>
                        `;
                    } else {
                        classes.push('br-booked');
                    }
                    
                    // Check if this week is selected
                    if (this.selectedWeek && this.selectedWeek.start === dateStr) {
                        classes.push('br-selected');
                    }
                }
                
                // Check if date is in the past
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (currentDate < today) {
                    classes.push('br-past');
                }
                
                html += `<td class="${classes.join(' ')}" data-date="${dateStr}">${content}</td>`;
                
                // New row after Saturday
                if (dayOfWeek === 6 && day < daysInMonth) {
                    html += '</tr><tr>';
                }
            }
            
            // Fill remaining cells
            const lastDay = new Date(year, month, daysInMonth).getDay();
            for (let i = lastDay + 1; i <= 6; i++) {
                html += '<td></td>';
            }
            
            html += '</tr></tbody></table></div>';
            
            return html;
        }
        
        getWeekData(dateStr) {
            if (!this.calendarData || !this.calendarData.pricing_data) {
                return null;
            }
            return this.calendarData.pricing_data[dateStr] || null;
        }
        
        bindEvents() {
            const self = this;
            
            // Click on available week
            this.container.on('click', '.br-week-start.br-available:not(.br-past)', function() {
                const startDate = $(this).data('date');
                self.selectWeek(startDate);
            });
            
            // Form submission
            this.container.on('submit', '#br-calendar-form', function(e) {
                e.preventDefault();
                self.submitBooking();
            });
        }
        
        selectWeek(startDate) {
            // Clear previous selection
            this.container.find('.br-selected').removeClass('br-selected');
            
            // Calculate end date (7 days later)
            const start = new Date(startDate);
            const end = new Date(start);
            end.setDate(end.getDate() + 6);
            
            // Get week data
            const weekData = this.getWeekData(startDate);
            if (!weekData || !weekData.available) {
                return;
            }
            
            // Set selected week
            this.selectedWeek = {
                start: startDate,
                end: this.formatDate(end),
                rate: weekData.rate
            };
            
            // Highlight selected week
            this.highlightWeek(startDate);
            
            // Update form
            this.updateBookingForm();
            
            // Show form
            this.container.find('.br-calendar-booking-form').slideDown();
            
            // Scroll to form
            $('html, body').animate({
                scrollTop: this.container.find('.br-calendar-booking-form').offset().top - 100
            }, 500);
        }
        
        highlightWeek(startDate) {
            const start = new Date(startDate);
            
            // Add selected class to all days in the week
            for (let i = 0; i < 7; i++) {
                const currentDate = new Date(start);
                currentDate.setDate(currentDate.getDate() + i);
                const dateStr = this.formatDate(currentDate);
                
                this.container.find(`[data-date="${dateStr}"]`).addClass('br-selected');
            }
        }
        
        updateBookingForm() {
            if (!this.selectedWeek) return;
            
            // Update display
            const startDate = new Date(this.selectedWeek.start);
            const endDate = new Date(this.selectedWeek.end);
            
            $('#br-checkin-display').text(this.formatDisplayDate(startDate));
            $('#br-checkout-display').text(this.formatDisplayDate(endDate));
            $('#br-price-display').text(br_calendar.currency_symbol + this.selectedWeek.rate.toLocaleString());
            
            // Update hidden fields
            $('#br-checkin-date').val(this.selectedWeek.start);
            $('#br-checkout-date').val(this.selectedWeek.end);
            $('#br-weekly-rate').val(this.selectedWeek.rate);
        }
        
        submitBooking() {
            const self = this;
            const form = $('#br-calendar-form');
            const submitBtn = form.find('.br-submit-btn');
            const messageDiv = this.container.find('.br-form-message');
            
            // Validate
            if (!this.validateForm()) {
                this.showMessage(br_calendar.strings.please_fill_all, 'error');
                return;
            }
            
            // Disable submit button
            submitBtn.prop('disabled', true).text(br_calendar.strings.loading);
            
            // Prepare data
            const formData = form.serialize() + '&action=br_submit_calendar_booking&nonce=' + br_calendar.nonce;
            
            // Submit
            $.ajax({
                url: br_calendar.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success');
                        form[0].reset();
                        self.selectedWeek = null;
                        self.container.find('.br-calendar-booking-form').slideUp();
                        self.loadCalendarData(); // Reload to show updated availability
                    } else {
                        self.showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showMessage(br_calendar.strings.error, 'error');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text('Submit Booking Request');
                }
            });
        }
        
        validateForm() {
            let isValid = true;
            
            $('#br-calendar-form [required]').each(function() {
                if (!$(this).val().trim()) {
                    $(this).addClass('error');
                    isValid = false;
                } else {
                    $(this).removeClass('error');
                }
            });
            
            // Validate email
            const email = $('#br-email').val();
            if (email && !this.isValidEmail(email)) {
                $('#br-email').addClass('error');
                isValid = false;
            }
            
            return isValid;
        }
        
        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
        
        showMessage(message, type) {
            const messageDiv = this.container.find('.br-form-message');
            messageDiv.removeClass('success error').addClass(type).html(message).show();
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                messageDiv.fadeOut();
            }, 5000);
        }
        
        showError(message) {
            this.container.find('.br-calendar-loading').html(
                '<div class="br-error-message">' + message + '</div>'
            );
        }
        
        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        formatDisplayDate(date) {
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }
    }
    
    // Initialize all calendar widgets
    $('.br-calendar-widget').each(function() {
        new BookingCalendar(this);
    });
});