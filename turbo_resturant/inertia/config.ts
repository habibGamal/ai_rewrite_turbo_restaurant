import moment from 'moment'

export const WATI_TIME_FOR_SEARCH = 500
export const configMomentLocaleAr = () =>
  moment.locale('ar', {
    months: 'يناير_فبراير_مارس_أبريل_مايو_يونيو_يوليو_أغسطس_سبتمبر_أكتوبر_نوفمبر_ديسمبر'.split('_'),
    monthsShort: 'يناير_فبراير_مارس_أبريل_مايو_يونيو_يوليو_أغسطس_سبتمبر_أكتوبر_نوفمبر_ديسمبر'.split(
      '_'
    ),
    monthsParseExact: true,
    weekdays: 'الأحد_الاثنين_الثلاثاء_الأربعاء_الخميس_الجمعة_السبت'.split('_'),
    weekdaysShort: 'الأحد_الاثنين_الثلاثاء_الأربعاء_الخميس_الجمعة_السبت'.split('_'),
    weekdaysMin: 'ح_ن_ث_ر_خ_ج_س'.split('_'),
    weekdaysParseExact: true,
    longDateFormat: {
      LT: 'HH:mm',
      LTS: 'HH:mm:ss',
      L: 'YYYY/MM/DD',
      LL: 'D MMMM YYYY',
      LLL: 'D MMMM YYYY HH:mm',
      LLLL: 'dddd D MMMM YYYY HH:mm',
    },
    calendar: {
      sameDay: '[اليوم في] LT',
      nextDay: '[غدًا في] LT',
      nextWeek: 'dddd [في] LT',
      lastDay: '[أمس في] LT',
      lastWeek: 'dddd [الماضي في] LT',
      sameElse: 'L',
    },
    relativeTime: {
      future: 'بعد %s',
      past: 'منذ %s',
      s: 'ثوانٍ',
      m: 'دقيقة',
      mm: '%d دقائق',
      h: 'ساعة',
      hh: '%d ساعات',
      d: 'يوم',
      dd: '%d أيام',
      M: 'شهر',
      MM: '%d أشهر',
      y: 'سنة',
      yy: '%d سنوات',
    },
    dayOfMonthOrdinalParse: /\d{1,2}(th|st|nd|rd)/,
    ordinal: function (number) {
      var b = number % 10
      var output =
        ~~((number % 100) / 10) === 1
          ? 'th'
          : b === 1
            ? 'st'
            : b === 2
              ? 'nd'
              : b === 3
                ? 'rd'
                : 'th'
      return number + output
    },
    meridiemParse: /ص|م/,
    isPM: function (input) {
      return input === 'م'
    },
    meridiem: function (hours, minutes, isLower) {
      if (hours < 12) {
        return 'ص'
      } else {
        return 'م'
      }
    },
    week: {
      dow: 0, // Sunday is the first day of the week.
      doy: 6, // Used to determine first week of the year.
    },
  })
