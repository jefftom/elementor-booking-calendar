jQuery(document).ready(function($) {
    'use strict';
    
    // Modern Multi-Week Calendar Widget
    class BookingCalendarV3 {
        constructor(container) {
            this.container = $(container);
            this.currentDate = new Date();
            this.selectedWeeks = []; // Array to store multiple weeks
            this.maxWeeks = 8; // Maximum 8 weeks
            this.calendarData = null;
            this.visibleMonths = 2;
            this.currentMonthOffset = 0;
            this.maxMonthsAhead = 12;
            
            // Get start day from widget data or global settings
            const widgetStartDay = this.container.data('start-day');
            if (widgetStartDay === 'sunday') {
                this.startDayNumber = 0;
            } else if (widgetStartDay === 'monday') {
                this.startDayNumber = 1;
            } else if (widgetStartDay === 'saturday') {
                this.startDayNumber = 6;
            } else {
                // Default to Sunday
                this.startDayNumber = 0;
            }
            
            console.log('Calendar initialized with start day:', this.startDayNumber, 'from widget:', widgetStartDay);
            
            this.init();
        }
        
        init() {
            this.createCalendarStructure();
            this.loadCalendarData();
            this.bindEvents();
        }
        
        createCalendarStructure() {
            const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const startDayName = dayNames[this.startDayNumber];
            
            const html = `
                <div class="br-calendar-v3">
                    <div class="br-calendar-header">
                        <div class="br-calendar-title">
                            <h3>Select your weeks</h3>
                            <p>Click on ${startDayName}s to select weeks (${startDayName} to ${startDayName}, 7 nights each)</p>
                            <p class="br-selection-info">You can select up to ${this.maxWeeks} weeks</p>
                        </div>
                    </div>
                    
                    <div class="br-calendar-container">
                        <div class="br-calendar-nav">
                            <button class="br-nav-prev" disabled>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                <span>Previous Months</span>
                            </button>
                            <div class="br-nav-months"></div>
                            <button class="br-nav-next">
                                <span>Next Months</span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="br-calendar-scroll-container">
                            <div class="br-calendar-months-wrapper">
                                <div class="br-calendar-loading">
                                    <div class="br-spinner"></div>
                                    <p>Loading availability...</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="br-selected-weeks-summary" style="display: none;">
                            <h4>Selected Weeks: <span class="br-week-count">0</span></h4>
                            <div class="br-weeks-list"></div>
                            <div class="br-total-price">
                                <span>Total Price:</span>
                                <strong class="br-price-total">€0</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="br-booking-form-container" style="display: none;">
                        <div class="br-form-header">
                            <h3>Complete your booking</h3>
                        </div>
                        
                        <form id="br-booking-form-v3">
                            <div class="br-form-grid">
                                <div class="br-form-group">
                                    <label>First Name *</label>
                                    <input type="text" name="first_name" required>
                                </div>
                                <div class="br-form-group">
                                    <label>Last Name *</label>
                                    <input type="text" name="last_name" required>
                                </div>
                            </div>
                            
                            <div class="br-form-grid">
                                <div class="br-form-group">
                                    <label>Email *</label>
                                    <input type="email" name="email" required>
                                </div>
                                <div class="br-form-group">
                                    <label>Phone</label>
                                    <input type="tel" name="phone">
                                </div>
                            </div>
                            
                            <div class="br-form-group">
                                <label>Special Requests</label>
                                <textarea name="details" rows="3" placeholder="Any special requests or notes..."></textarea>
                            </div>
                            
                            <input type="hidden" name="booking_weeks" id="br-booking-weeks">
                            
                            <div class="br-form-actions">
                                <button type="button" class="br-btn-secondary br-change-dates">Change Dates</button>
                                <button type="submit" class="br-btn-primary">
                                    <span class="btn-text">Request Booking</span>
                                    <span class="btn-loading" style="display: none;">
                                        <span class="br-spinner-small"></span> Processing...
                                    </span>
                                </button>
                            </div>
                        </form>
                        
                        <div class="br-form-message"></div>
                    </div>
                </div>
            `;
            
            this.container.html(html);
        }
        
        loadCalendarData() {
            const self = this;
            const startDate = new Date();
            const endDate = new Date();
            endDate.setMonth(endDate.getMonth() + this.maxMonthsAhead);
            
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
                        self.renderMonths();
                        self.container.find('.br-calendar-loading').hide();
                    }
                },
                error: function() {
                    self.showError('Failed to load calendar data');
                }
            });
        }
        
        renderMonths() {
            const wrapper = this.container.find('.br-calendar-months-wrapper');
            wrapper.empty();
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            for (let i = 0; i < this.visibleMonths; i++) {
                const monthDate = new Date(today.getFullYear(), today.getMonth() + this.currentMonthOffset + i, 1);
                wrapper.append(this.renderMonth(monthDate));
            }
            
            this.updateNavigation();
            this.updateNavMonths();
        }
        
        renderMonth(date) {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
            const dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            
            const year = date.getFullYear();
            const month = date.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
            let html = `
                <div class="br-calendar-month">
                    <div class="br-month-header">
                        <h4>${monthNames[month]} ${year}</h4>
                    </div>
                    <div class="br-month-grid">
                        <div class="br-weekdays">
            `;
            
            // Weekday headers
            for (let day of dayNames) {
                html += `<div class="br-weekday">${day}</div>`;
            }
            html += '</div><div class="br-days">';
            
            // Empty cells before first day
            for (let i = 0; i < firstDay; i++) {
                html += '<div class="br-day br-empty"></div>';
            }
            
            // Days of month
            for (let day = 1; day <= daysInMonth; day++) {
                const currentDate = new Date(year, month, day);
                const dateStr = this.formatDate(currentDate);
                const dayOfWeek = currentDate.getDay();
                const isStartDay = dayOfWeek === this.startDayNumber;
                
                let classes = ['br-day'];
                let content = `<span class="br-day-number">${day}</span>`;
                
                // Check if this date is part of any selected week
                const weekInfo = this.getWeekForDate(currentDate);
                if (weekInfo && this.isWeekSelected(weekInfo.start)) {
                    classes.push('br-selected');
                    if (dateStr === weekInfo.start) {
                        classes.push('br-selected-start');
                    }
                    if (dateStr === weekInfo.end) {
                        classes.push('br-selected-end');
                    }
                }
                
                if (isStartDay) {
                    const weekData = this.getWeekData(dateStr);
                    if (weekData) {
                        classes.push('br-week-start');
                        
                        if (weekData.available && !this.isPastDate(currentDate)) {
                            classes.push('br-available');
                            content += `<span class="br-day-price">${br_calendar.currency_symbol}${weekData.rate.toLocaleString()}</span>`;
                        } else {
                            classes.push('br-unavailable');
                        }
                    }
                }
                
                if (this.isPastDate(currentDate)) {
                    classes.push('br-past');
                }
                
                html += `<div class="${classes.join(' ')}" data-date="${dateStr}">${content}</div>`;
            }
            
            // Fill remaining cells
            const totalCells = firstDay + daysInMonth;
            const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (let i = 0; i < remainingCells; i++) {
                html += '<div class="br-day br-empty"></div>';
            }
            
            html += '</div></div></div>';
            
            return html;
        }
        
        bindEvents() {
            const self = this;
            
            // Navigation
            this.container.on('click', '.br-nav-prev', function() {
                if (!$(this).prop('disabled')) {
                    self.currentMonthOffset -= self.visibleMonths;
                    self.renderMonths();
                }
            });
            
            this.container.on('click', '.br-nav-next', function() {
                if (!$(this).prop('disabled')) {
                    self.currentMonthOffset += self.visibleMonths;
                    self.renderMonths();
                }
            });
            
            // Week selection
            this.container.on('click', '.br-week-start.br-available:not(.br-past)', function() {
                const startDate = $(this).data('date');
                self.toggleWeek(startDate);
            });
            
            // Continue to booking
            this.container.on('click', '.br-continue-booking', function() {
                self.showBookingForm();
            });
            
            // Form submission
            this.container.on('submit', '#br-booking-form-v3', function(e) {
                e.preventDefault();
                self.submitBooking();
            });
            
            // Change dates
            this.container.on('click', '.br-change-dates', function() {
                self.resetSelection();
            });
        }
        
        toggleWeek(startDate) {
            const weekIndex = this.selectedWeeks.findIndex(week => week.start === startDate);
            
            if (weekIndex > -1) {
                // Remove week
                this.selectedWeeks.splice(weekIndex, 1);
            } else if (this.selectedWeeks.length < this.maxWeeks) {
                // Add week - Parse the date properly as local date
                const start = this.parseDate(startDate);
                const end = new Date(start);
                end.setDate(end.getDate() + 6); // Exactly 6 days later for 7 nights
                
                const weekData = this.getWeekData(startDate);
                if (weekData && weekData.available) {
                    this.selectedWeeks.push({
                        start: startDate,
                        end: this.formatDate(end),
                        rate: weekData.rate
                    });
                    
                    // Sort weeks by start date
                    this.selectedWeeks.sort((a, b) => this.parseDate(a.start) - this.parseDate(b.start));
                }
            } else {
                alert(`You can select a maximum of ${this.maxWeeks} weeks`);
                return;
            }
            
            this.renderMonths();
            this.updateSelectedWeeksSummary();
        }
        
        updateSelectedWeeksSummary() {
            const summary = this.container.find('.br-selected-weeks-summary');
            const weeksList = summary.find('.br-weeks-list');
            
            if (this.selectedWeeks.length === 0) {
                summary.hide();
                this.container.find('.br-booking-form-container').hide();
                return;
            }
            
            weeksList.empty();
            let totalPrice = 0;
            
            this.selectedWeeks.forEach((week, index) => {
                const startDate = this.parseDate(week.start);
                const endDate = this.parseDate(week.end);
                
                // Debug log
                console.log(`Week ${index + 1}: Start=${week.start} (${this.formatDisplayDate(startDate)}), End=${week.end} (${this.formatDisplayDate(endDate)})`);
                
                weeksList.append(`
                    <div class="br-week-item">
                        <span class="br-week-dates">
                            ${this.formatDisplayDate(startDate)} - ${this.formatDisplayDate(endDate)}
                        </span>
                        <span class="br-week-price">${br_calendar.currency_symbol}${week.rate.toLocaleString()}</span>
                        <button class="br-remove-week" data-index="${index}">×</button>
                    </div>
                `);
                
                totalPrice += week.rate;
            });
            
            summary.find('.br-week-count').text(this.selectedWeeks.length);
            summary.find('.br-price-total').text(br_calendar.currency_symbol + totalPrice.toLocaleString());
            summary.show();
            
            // Show continue button
            if (!summary.find('.br-continue-booking').length) {
                summary.append('<button class="br-continue-booking br-btn-primary">Continue to Booking</button>');
            }
            
            // Handle remove week - need to use 'self' reference
            const self = this;
            weeksList.find('.br-remove-week').off('click').on('click', function() {
                const index = $(this).data('index');
                const week = self.selectedWeeks[index];
                self.toggleWeek(week.start);
            });
        }
        
        showBookingForm() {
            this.container.find('.br-booking-form-container').slideDown();
            
            // Prepare booking data
            const bookingData = {
                weeks: this.selectedWeeks,
                total_weeks: this.selectedWeeks.length,
                total_price: this.selectedWeeks.reduce((sum, week) => sum + week.rate, 0)
            };
            
            $('#br-booking-weeks').val(JSON.stringify(bookingData));
            
            // Scroll to form
            $('html, body').animate({
                scrollTop: this.container.find('.br-booking-form-container').offset().top - 100
            }, 500);
        }
        
        resetSelection() {
            this.selectedWeeks = [];
            this.renderMonths();
            this.updateSelectedWeeksSummary();
            this.container.find('#br-booking-form-v3')[0].reset();
        }
        
        submitBooking() {
            const self = this;
            const form = $('#br-booking-form-v3');
            const submitBtn = form.find('.br-btn-primary');
            
            if (!form[0].checkValidity()) {
                form[0].reportValidity();
                return;
            }
            
            // For now, submit the first week only (until backend supports multi-week)
            const firstWeek = this.selectedWeeks[0];
            
            submitBtn.prop('disabled', true);
            submitBtn.find('.btn-text').hide();
            submitBtn.find('.btn-loading').show();
            
            const formData = {
                action: 'br_submit_calendar_booking',
                nonce: br_calendar.nonce,
                first_name: form.find('[name="first_name"]').val(),
                last_name: form.find('[name="last_name"]').val(),
                email: form.find('[name="email"]').val(),
                phone: form.find('[name="phone"]').val(),
                details: form.find('[name="details"]').val() + '\n\nMultiple weeks selected: ' + JSON.stringify(this.selectedWeeks),
                checkin_date: firstWeek.start,
                checkout_date: firstWeek.end
            };
            
            $.ajax({
                url: br_calendar.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success');
                        form[0].reset();
                        
                        setTimeout(() => {
                            self.resetSelection();
                            self.loadCalendarData();
                        }, 3000);
                    } else {
                        self.showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showMessage('An error occurred. Please try again.', 'error');
                },
                complete: function() {
                    submitBtn.prop('disabled', false);
                    submitBtn.find('.btn-text').show();
                    submitBtn.find('.btn-loading').hide();
                }
            });
        }
        
        // Helper methods
        getWeekForDate(date) {
            // This function should return the week that starts on the clicked date
            // The clicked date IS the start date if it's a start day
            const dayOfWeek = date.getDay();
            
            if (dayOfWeek === this.startDayNumber) {
                // This IS a start day, so the week starts on this date
                const weekStart = new Date(date);
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekEnd.getDate() + 6);
                
                return {
                    start: this.formatDate(weekStart),
                    end: this.formatDate(weekEnd)
                };
            }
            
            // Not a start day - find the previous start day
            const daysFromStart = (dayOfWeek - this.startDayNumber + 7) % 7;
            const weekStart = new Date(date);
            weekStart.setDate(weekStart.getDate() - daysFromStart);
            
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekEnd.getDate() + 6);
            
            return {
                start: this.formatDate(weekStart),
                end: this.formatDate(weekEnd)
            };
        }
        
        isWeekSelected(startDate) {
            return this.selectedWeeks.some(week => week.start === startDate);
        }
        
        updateNavigation() {
            const prevBtn = this.container.find('.br-nav-prev');
            const nextBtn = this.container.find('.br-nav-next');
            
            // Disable previous button if we're at the current month or earlier
            const today = new Date();
            const firstVisibleMonth = new Date(today.getFullYear(), today.getMonth() + this.currentMonthOffset, 1);
            const currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            
            prevBtn.prop('disabled', firstVisibleMonth <= currentMonth);
            nextBtn.prop('disabled', this.currentMonthOffset + this.visibleMonths >= this.maxMonthsAhead);
        }
        
        updateNavMonths() {
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                               'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const today = new Date();
            const firstMonth = new Date(today.getFullYear(), today.getMonth() + this.currentMonthOffset, 1);
            const lastMonth = new Date(today.getFullYear(), today.getMonth() + this.currentMonthOffset + this.visibleMonths - 1, 1);
            
            const text = `${monthNames[firstMonth.getMonth()]} ${firstMonth.getFullYear()} - ${monthNames[lastMonth.getMonth()]} ${lastMonth.getFullYear()}`;
            this.container.find('.br-nav-months').text(text);
        }
        
        getWeekData(dateStr) {
            if (!this.calendarData || !this.calendarData.pricing_data) {
                return null;
            }
            return this.calendarData.pricing_data[dateStr] || null;
        }
        
        isPastDate(date) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return date < today;
        }
        
        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        parseDate(dateStr) {
            // Parse date string as local date, not UTC
            const [year, month, day] = dateStr.split('-').map(num => parseInt(num));
            return new Date(year, month - 1, day);
        }
        
        formatDisplayDate(date) {
            // Ensure we're working with a valid date object
            if (!(date instanceof Date) || isNaN(date)) {
                console.error('Invalid date passed to formatDisplayDate:', date);
                return 'Invalid Date';
            }
            
            const options = { weekday: 'short', month: 'short', day: 'numeric' };
            return date.toLocaleDateString('en-US', options);
        }
        
        showMessage(message, type) {
            const messageDiv = this.container.find('.br-form-message');
            messageDiv.removeClass('success error').addClass(type).html(message).show();
            
            setTimeout(() => {
                messageDiv.fadeOut();
            }, 5000);
        }
        
        showError(message) {
            this.container.find('.br-calendar-loading').html(
                '<div class="br-error-message">' + message + '</div>'
            );
        }
    }
    
    // Initialize calendars - one instance per widget
    $('.br-calendar-widget').each(function(index) {
        // Add unique identifier to prevent overlap
        $(this).addClass('br-calendar-instance-' + index);
        new BookingCalendarV3(this);
    });
});