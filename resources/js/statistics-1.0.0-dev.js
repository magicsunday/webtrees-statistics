/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 *
 * Version: 1.0.0-dev
 */

(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
  typeof define === 'function' && define.amd ? define(['exports'], factory) :
  (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.WebtreesStatistic = {}));
})(this, (function (exports) { 'use strict';

  function responseJson(response) {
    if (!response.ok) throw new Error(response.status + " " + response.statusText);
    if (response.status === 204 || response.status === 205) return;
    return response.json();
  }

  function json(input, init) {
    return fetch(input, init).then(responseJson);
  }

  function ascending$1(a, b) {
    return a == null || b == null ? NaN : a < b ? -1 : a > b ? 1 : a >= b ? 0 : NaN;
  }

  function descending$1(a, b) {
    return a == null || b == null ? NaN
      : b < a ? -1
      : b > a ? 1
      : b >= a ? 0
      : NaN;
  }

  function bisector(f) {
    let compare1, compare2, delta;

    // If an accessor is specified, promote it to a comparator. In this case we
    // can test whether the search value is (self-) comparable. We can’t do this
    // for a comparator (except for specific, known comparators) because we can’t
    // tell if the comparator is symmetric, and an asymmetric comparator can’t be
    // used to test whether a single value is comparable.
    if (f.length !== 2) {
      compare1 = ascending$1;
      compare2 = (d, x) => ascending$1(f(d), x);
      delta = (d, x) => f(d) - x;
    } else {
      compare1 = f === ascending$1 || f === descending$1 ? f : zero$2;
      compare2 = f;
      delta = f;
    }

    function left(a, x, lo = 0, hi = a.length) {
      if (lo < hi) {
        if (compare1(x, x) !== 0) return hi;
        do {
          const mid = (lo + hi) >>> 1;
          if (compare2(a[mid], x) < 0) lo = mid + 1;
          else hi = mid;
        } while (lo < hi);
      }
      return lo;
    }

    function right(a, x, lo = 0, hi = a.length) {
      if (lo < hi) {
        if (compare1(x, x) !== 0) return hi;
        do {
          const mid = (lo + hi) >>> 1;
          if (compare2(a[mid], x) <= 0) lo = mid + 1;
          else hi = mid;
        } while (lo < hi);
      }
      return lo;
    }

    function center(a, x, lo = 0, hi = a.length) {
      const i = left(a, x, lo, hi - 1);
      return i > lo && delta(a[i - 1], x) > -delta(a[i], x) ? i - 1 : i;
    }

    return {left, center, right};
  }

  function zero$2() {
    return 0;
  }

  function number$3(x) {
    return x === null ? NaN : +x;
  }

  const ascendingBisect = bisector(ascending$1);
  const bisectRight = ascendingBisect.right;
  bisector(number$3).center;

  function extent(values, valueof) {
    let min;
    let max;
    if (valueof === undefined) {
      for (const value of values) {
        if (value != null) {
          if (min === undefined) {
            if (value >= value) min = max = value;
          } else {
            if (min > value) min = value;
            if (max < value) max = value;
          }
        }
      }
    } else {
      let index = -1;
      for (let value of values) {
        if ((value = valueof(value, ++index, values)) != null) {
          if (min === undefined) {
            if (value >= value) min = max = value;
          } else {
            if (min > value) min = value;
            if (max < value) max = value;
          }
        }
      }
    }
    return [min, max];
  }

  // https://github.com/python/cpython/blob/a74eea238f5baba15797e2e8b570d153bc8690a7/Modules/mathmodule.c#L1423
  class Adder {
    constructor() {
      this._partials = new Float64Array(32);
      this._n = 0;
    }
    add(x) {
      const p = this._partials;
      let i = 0;
      for (let j = 0; j < this._n && j < 32; j++) {
        const y = p[j],
          hi = x + y,
          lo = Math.abs(x) < Math.abs(y) ? x - (hi - y) : y - (hi - x);
        if (lo) p[i++] = lo;
        x = hi;
      }
      p[i] = x;
      this._n = i + 1;
      return this;
    }
    valueOf() {
      const p = this._partials;
      let n = this._n, x, y, lo, hi = 0;
      if (n > 0) {
        hi = p[--n];
        while (n > 0) {
          x = hi;
          y = p[--n];
          hi = x + y;
          lo = y - (hi - x);
          if (lo) break;
        }
        if (n > 0 && ((lo < 0 && p[n - 1] < 0) || (lo > 0 && p[n - 1] > 0))) {
          y = lo * 2;
          x = hi + y;
          if (y == x - hi) hi = x;
        }
      }
      return hi;
    }
  }

  class InternMap extends Map {
    constructor(entries, key = keyof) {
      super();
      Object.defineProperties(this, {_intern: {value: new Map()}, _key: {value: key}});
      if (entries != null) for (const [key, value] of entries) this.set(key, value);
    }
    get(key) {
      return super.get(intern_get(this, key));
    }
    has(key) {
      return super.has(intern_get(this, key));
    }
    set(key, value) {
      return super.set(intern_set(this, key), value);
    }
    delete(key) {
      return super.delete(intern_delete(this, key));
    }
  }

  function intern_get({_intern, _key}, value) {
    const key = _key(value);
    return _intern.has(key) ? _intern.get(key) : value;
  }

  function intern_set({_intern, _key}, value) {
    const key = _key(value);
    if (_intern.has(key)) return _intern.get(key);
    _intern.set(key, value);
    return value;
  }

  function intern_delete({_intern, _key}, value) {
    const key = _key(value);
    if (_intern.has(key)) {
      value = _intern.get(key);
      _intern.delete(key);
    }
    return value;
  }

  function keyof(value) {
    return value !== null && typeof value === "object" ? value.valueOf() : value;
  }

  const e10 = Math.sqrt(50),
      e5 = Math.sqrt(10),
      e2 = Math.sqrt(2);

  function tickSpec(start, stop, count) {
    const step = (stop - start) / Math.max(0, count),
        power = Math.floor(Math.log10(step)),
        error = step / Math.pow(10, power),
        factor = error >= e10 ? 10 : error >= e5 ? 5 : error >= e2 ? 2 : 1;
    let i1, i2, inc;
    if (power < 0) {
      inc = Math.pow(10, -power) / factor;
      i1 = Math.round(start * inc);
      i2 = Math.round(stop * inc);
      if (i1 / inc < start) ++i1;
      if (i2 / inc > stop) --i2;
      inc = -inc;
    } else {
      inc = Math.pow(10, power) * factor;
      i1 = Math.round(start / inc);
      i2 = Math.round(stop / inc);
      if (i1 * inc < start) ++i1;
      if (i2 * inc > stop) --i2;
    }
    if (i2 < i1 && 0.5 <= count && count < 2) return tickSpec(start, stop, count * 2);
    return [i1, i2, inc];
  }

  function ticks(start, stop, count) {
    stop = +stop, start = +start, count = +count;
    if (!(count > 0)) return [];
    if (start === stop) return [start];
    const reverse = stop < start, [i1, i2, inc] = reverse ? tickSpec(stop, start, count) : tickSpec(start, stop, count);
    if (!(i2 >= i1)) return [];
    const n = i2 - i1 + 1, ticks = new Array(n);
    if (reverse) {
      if (inc < 0) for (let i = 0; i < n; ++i) ticks[i] = (i2 - i) / -inc;
      else for (let i = 0; i < n; ++i) ticks[i] = (i2 - i) * inc;
    } else {
      if (inc < 0) for (let i = 0; i < n; ++i) ticks[i] = (i1 + i) / -inc;
      else for (let i = 0; i < n; ++i) ticks[i] = (i1 + i) * inc;
    }
    return ticks;
  }

  function tickIncrement(start, stop, count) {
    stop = +stop, start = +start, count = +count;
    return tickSpec(start, stop, count)[2];
  }

  function tickStep(start, stop, count) {
    stop = +stop, start = +start, count = +count;
    const reverse = stop < start, inc = reverse ? tickIncrement(stop, start, count) : tickIncrement(start, stop, count);
    return (reverse ? -1 : 1) * (inc < 0 ? 1 / -inc : inc);
  }

  function max$3(values, valueof) {
    let max;
    if (valueof === undefined) {
      for (const value of values) {
        if (value != null
            && (max < value || (max === undefined && value >= value))) {
          max = value;
        }
      }
    } else {
      let index = -1;
      for (let value of values) {
        if ((value = valueof(value, ++index, values)) != null
            && (max < value || (max === undefined && value >= value))) {
          max = value;
        }
      }
    }
    return max;
  }

  function min$2(values, valueof) {
    let min;
    if (valueof === undefined) {
      for (const value of values) {
        if (value != null
            && (min > value || (min === undefined && value >= value))) {
          min = value;
        }
      }
    } else {
      let index = -1;
      for (let value of values) {
        if ((value = valueof(value, ++index, values)) != null
            && (min > value || (min === undefined && value >= value))) {
          min = value;
        }
      }
    }
    return min;
  }

  function* flatten(arrays) {
    for (const array of arrays) {
      yield* array;
    }
  }

  function merge(arrays) {
    return Array.from(flatten(arrays));
  }

  function range$1(start, stop, step) {
    start = +start, stop = +stop, step = (n = arguments.length) < 2 ? (stop = start, start = 0, 1) : n < 3 ? 1 : +step;

    var i = -1,
        n = Math.max(0, Math.ceil((stop - start) / step)) | 0,
        range = new Array(n);

    while (++i < n) {
      range[i] = start + i * step;
    }

    return range;
  }

  function sum$1(values, valueof) {
    let sum = 0;
    if (valueof === undefined) {
      for (let value of values) {
        if (value = +value) {
          sum += value;
        }
      }
    } else {
      let index = -1;
      for (let value of values) {
        if (value = +valueof(value, ++index, values)) {
          sum += value;
        }
      }
    }
    return sum;
  }

  var epsilon$5 = 1e-6;
  var pi$4 = Math.PI;
  var halfPi$2 = pi$4 / 2;
  var quarterPi = pi$4 / 4;
  var tau$4 = pi$4 * 2;

  var degrees$1 = 180 / pi$4;
  var radians = pi$4 / 180;

  var abs$3 = Math.abs;
  var atan = Math.atan;
  var atan2$1 = Math.atan2;
  var cos$2 = Math.cos;
  var exp = Math.exp;
  var log = Math.log;
  var sin$2 = Math.sin;
  var sign$1 = Math.sign || function(x) { return x > 0 ? 1 : x < 0 ? -1 : 0; };
  var sqrt$1 = Math.sqrt;
  var tan = Math.tan;

  function acos$1(x) {
    return x > 1 ? 0 : x < -1 ? pi$4 : Math.acos(x);
  }

  function asin$1(x) {
    return x > 1 ? halfPi$2 : x < -1 ? -halfPi$2 : Math.asin(x);
  }

  function noop$2() {}

  function streamGeometry(geometry, stream) {
    if (geometry && streamGeometryType.hasOwnProperty(geometry.type)) {
      streamGeometryType[geometry.type](geometry, stream);
    }
  }

  var streamObjectType = {
    Feature: function(object, stream) {
      streamGeometry(object.geometry, stream);
    },
    FeatureCollection: function(object, stream) {
      var features = object.features, i = -1, n = features.length;
      while (++i < n) streamGeometry(features[i].geometry, stream);
    }
  };

  var streamGeometryType = {
    Sphere: function(object, stream) {
      stream.sphere();
    },
    Point: function(object, stream) {
      object = object.coordinates;
      stream.point(object[0], object[1], object[2]);
    },
    MultiPoint: function(object, stream) {
      var coordinates = object.coordinates, i = -1, n = coordinates.length;
      while (++i < n) object = coordinates[i], stream.point(object[0], object[1], object[2]);
    },
    LineString: function(object, stream) {
      streamLine(object.coordinates, stream, 0);
    },
    MultiLineString: function(object, stream) {
      var coordinates = object.coordinates, i = -1, n = coordinates.length;
      while (++i < n) streamLine(coordinates[i], stream, 0);
    },
    Polygon: function(object, stream) {
      streamPolygon(object.coordinates, stream);
    },
    MultiPolygon: function(object, stream) {
      var coordinates = object.coordinates, i = -1, n = coordinates.length;
      while (++i < n) streamPolygon(coordinates[i], stream);
    },
    GeometryCollection: function(object, stream) {
      var geometries = object.geometries, i = -1, n = geometries.length;
      while (++i < n) streamGeometry(geometries[i], stream);
    }
  };

  function streamLine(coordinates, stream, closed) {
    var i = -1, n = coordinates.length - closed, coordinate;
    stream.lineStart();
    while (++i < n) coordinate = coordinates[i], stream.point(coordinate[0], coordinate[1], coordinate[2]);
    stream.lineEnd();
  }

  function streamPolygon(coordinates, stream) {
    var i = -1, n = coordinates.length;
    stream.polygonStart();
    while (++i < n) streamLine(coordinates[i], stream, 1);
    stream.polygonEnd();
  }

  function geoStream(object, stream) {
    if (object && streamObjectType.hasOwnProperty(object.type)) {
      streamObjectType[object.type](object, stream);
    } else {
      streamGeometry(object, stream);
    }
  }

  function spherical(cartesian) {
    return [atan2$1(cartesian[1], cartesian[0]), asin$1(cartesian[2])];
  }

  function cartesian(spherical) {
    var lambda = spherical[0], phi = spherical[1], cosPhi = cos$2(phi);
    return [cosPhi * cos$2(lambda), cosPhi * sin$2(lambda), sin$2(phi)];
  }

  function cartesianDot(a, b) {
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
  }

  function cartesianCross(a, b) {
    return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
  }

  // TODO return a
  function cartesianAddInPlace(a, b) {
    a[0] += b[0], a[1] += b[1], a[2] += b[2];
  }

  function cartesianScale(vector, k) {
    return [vector[0] * k, vector[1] * k, vector[2] * k];
  }

  // TODO return d
  function cartesianNormalizeInPlace(d) {
    var l = sqrt$1(d[0] * d[0] + d[1] * d[1] + d[2] * d[2]);
    d[0] /= l, d[1] /= l, d[2] /= l;
  }

  function compose(a, b) {

    function compose(x, y) {
      return x = a(x, y), b(x[0], x[1]);
    }

    if (a.invert && b.invert) compose.invert = function(x, y) {
      return x = b.invert(x, y), x && a.invert(x[0], x[1]);
    };

    return compose;
  }

  function rotationIdentity(lambda, phi) {
    if (abs$3(lambda) > pi$4) lambda -= Math.round(lambda / tau$4) * tau$4;
    return [lambda, phi];
  }

  rotationIdentity.invert = rotationIdentity;

  function rotateRadians(deltaLambda, deltaPhi, deltaGamma) {
    return (deltaLambda %= tau$4) ? (deltaPhi || deltaGamma ? compose(rotationLambda(deltaLambda), rotationPhiGamma(deltaPhi, deltaGamma))
      : rotationLambda(deltaLambda))
      : (deltaPhi || deltaGamma ? rotationPhiGamma(deltaPhi, deltaGamma)
      : rotationIdentity);
  }

  function forwardRotationLambda(deltaLambda) {
    return function(lambda, phi) {
      lambda += deltaLambda;
      if (abs$3(lambda) > pi$4) lambda -= Math.round(lambda / tau$4) * tau$4;
      return [lambda, phi];
    };
  }

  function rotationLambda(deltaLambda) {
    var rotation = forwardRotationLambda(deltaLambda);
    rotation.invert = forwardRotationLambda(-deltaLambda);
    return rotation;
  }

  function rotationPhiGamma(deltaPhi, deltaGamma) {
    var cosDeltaPhi = cos$2(deltaPhi),
        sinDeltaPhi = sin$2(deltaPhi),
        cosDeltaGamma = cos$2(deltaGamma),
        sinDeltaGamma = sin$2(deltaGamma);

    function rotation(lambda, phi) {
      var cosPhi = cos$2(phi),
          x = cos$2(lambda) * cosPhi,
          y = sin$2(lambda) * cosPhi,
          z = sin$2(phi),
          k = z * cosDeltaPhi + x * sinDeltaPhi;
      return [
        atan2$1(y * cosDeltaGamma - k * sinDeltaGamma, x * cosDeltaPhi - z * sinDeltaPhi),
        asin$1(k * cosDeltaGamma + y * sinDeltaGamma)
      ];
    }

    rotation.invert = function(lambda, phi) {
      var cosPhi = cos$2(phi),
          x = cos$2(lambda) * cosPhi,
          y = sin$2(lambda) * cosPhi,
          z = sin$2(phi),
          k = z * cosDeltaGamma - y * sinDeltaGamma;
      return [
        atan2$1(y * cosDeltaGamma + z * sinDeltaGamma, x * cosDeltaPhi + k * sinDeltaPhi),
        asin$1(k * cosDeltaPhi - x * sinDeltaPhi)
      ];
    };

    return rotation;
  }

  function rotation(rotate) {
    rotate = rotateRadians(rotate[0] * radians, rotate[1] * radians, rotate.length > 2 ? rotate[2] * radians : 0);

    function forward(coordinates) {
      coordinates = rotate(coordinates[0] * radians, coordinates[1] * radians);
      return coordinates[0] *= degrees$1, coordinates[1] *= degrees$1, coordinates;
    }

    forward.invert = function(coordinates) {
      coordinates = rotate.invert(coordinates[0] * radians, coordinates[1] * radians);
      return coordinates[0] *= degrees$1, coordinates[1] *= degrees$1, coordinates;
    };

    return forward;
  }

  // Generates a circle centered at [0°, 0°], with a given radius and precision.
  function circleStream(stream, radius, delta, direction, t0, t1) {
    if (!delta) return;
    var cosRadius = cos$2(radius),
        sinRadius = sin$2(radius),
        step = direction * delta;
    if (t0 == null) {
      t0 = radius + direction * tau$4;
      t1 = radius - step / 2;
    } else {
      t0 = circleRadius(cosRadius, t0);
      t1 = circleRadius(cosRadius, t1);
      if (direction > 0 ? t0 < t1 : t0 > t1) t0 += direction * tau$4;
    }
    for (var point, t = t0; direction > 0 ? t > t1 : t < t1; t -= step) {
      point = spherical([cosRadius, -sinRadius * cos$2(t), -sinRadius * sin$2(t)]);
      stream.point(point[0], point[1]);
    }
  }

  // Returns the signed angle of a cartesian point relative to [cosRadius, 0, 0].
  function circleRadius(cosRadius, point) {
    point = cartesian(point), point[0] -= cosRadius;
    cartesianNormalizeInPlace(point);
    var radius = acos$1(-point[1]);
    return ((-point[2] < 0 ? -radius : radius) + tau$4 - epsilon$5) % tau$4;
  }

  function clipBuffer() {
    var lines = [],
        line;
    return {
      point: function(x, y, m) {
        line.push([x, y, m]);
      },
      lineStart: function() {
        lines.push(line = []);
      },
      lineEnd: noop$2,
      rejoin: function() {
        if (lines.length > 1) lines.push(lines.pop().concat(lines.shift()));
      },
      result: function() {
        var result = lines;
        lines = [];
        line = null;
        return result;
      }
    };
  }

  function pointEqual(a, b) {
    return abs$3(a[0] - b[0]) < epsilon$5 && abs$3(a[1] - b[1]) < epsilon$5;
  }

  function Intersection(point, points, other, entry) {
    this.x = point;
    this.z = points;
    this.o = other; // another intersection
    this.e = entry; // is an entry?
    this.v = false; // visited
    this.n = this.p = null; // next & previous
  }

  // A generalized polygon clipping algorithm: given a polygon that has been cut
  // into its visible line segments, and rejoins the segments by interpolating
  // along the clip edge.
  function clipRejoin(segments, compareIntersection, startInside, interpolate, stream) {
    var subject = [],
        clip = [],
        i,
        n;

    segments.forEach(function(segment) {
      if ((n = segment.length - 1) <= 0) return;
      var n, p0 = segment[0], p1 = segment[n], x;

      if (pointEqual(p0, p1)) {
        if (!p0[2] && !p1[2]) {
          stream.lineStart();
          for (i = 0; i < n; ++i) stream.point((p0 = segment[i])[0], p0[1]);
          stream.lineEnd();
          return;
        }
        // handle degenerate cases by moving the point
        p1[0] += 2 * epsilon$5;
      }

      subject.push(x = new Intersection(p0, segment, null, true));
      clip.push(x.o = new Intersection(p0, null, x, false));
      subject.push(x = new Intersection(p1, segment, null, false));
      clip.push(x.o = new Intersection(p1, null, x, true));
    });

    if (!subject.length) return;

    clip.sort(compareIntersection);
    link$1(subject);
    link$1(clip);

    for (i = 0, n = clip.length; i < n; ++i) {
      clip[i].e = startInside = !startInside;
    }

    var start = subject[0],
        points,
        point;

    while (1) {
      // Find first unvisited intersection.
      var current = start,
          isSubject = true;
      while (current.v) if ((current = current.n) === start) return;
      points = current.z;
      stream.lineStart();
      do {
        current.v = current.o.v = true;
        if (current.e) {
          if (isSubject) {
            for (i = 0, n = points.length; i < n; ++i) stream.point((point = points[i])[0], point[1]);
          } else {
            interpolate(current.x, current.n.x, 1, stream);
          }
          current = current.n;
        } else {
          if (isSubject) {
            points = current.p.z;
            for (i = points.length - 1; i >= 0; --i) stream.point((point = points[i])[0], point[1]);
          } else {
            interpolate(current.x, current.p.x, -1, stream);
          }
          current = current.p;
        }
        current = current.o;
        points = current.z;
        isSubject = !isSubject;
      } while (!current.v);
      stream.lineEnd();
    }
  }

  function link$1(array) {
    if (!(n = array.length)) return;
    var n,
        i = 0,
        a = array[0],
        b;
    while (++i < n) {
      a.n = b = array[i];
      b.p = a;
      a = b;
    }
    a.n = b = array[0];
    b.p = a;
  }

  function longitude(point) {
    return abs$3(point[0]) <= pi$4 ? point[0] : sign$1(point[0]) * ((abs$3(point[0]) + pi$4) % tau$4 - pi$4);
  }

  function polygonContains(polygon, point) {
    var lambda = longitude(point),
        phi = point[1],
        sinPhi = sin$2(phi),
        normal = [sin$2(lambda), -cos$2(lambda), 0],
        angle = 0,
        winding = 0;

    var sum = new Adder();

    if (sinPhi === 1) phi = halfPi$2 + epsilon$5;
    else if (sinPhi === -1) phi = -halfPi$2 - epsilon$5;

    for (var i = 0, n = polygon.length; i < n; ++i) {
      if (!(m = (ring = polygon[i]).length)) continue;
      var ring,
          m,
          point0 = ring[m - 1],
          lambda0 = longitude(point0),
          phi0 = point0[1] / 2 + quarterPi,
          sinPhi0 = sin$2(phi0),
          cosPhi0 = cos$2(phi0);

      for (var j = 0; j < m; ++j, lambda0 = lambda1, sinPhi0 = sinPhi1, cosPhi0 = cosPhi1, point0 = point1) {
        var point1 = ring[j],
            lambda1 = longitude(point1),
            phi1 = point1[1] / 2 + quarterPi,
            sinPhi1 = sin$2(phi1),
            cosPhi1 = cos$2(phi1),
            delta = lambda1 - lambda0,
            sign = delta >= 0 ? 1 : -1,
            absDelta = sign * delta,
            antimeridian = absDelta > pi$4,
            k = sinPhi0 * sinPhi1;

        sum.add(atan2$1(k * sign * sin$2(absDelta), cosPhi0 * cosPhi1 + k * cos$2(absDelta)));
        angle += antimeridian ? delta + sign * tau$4 : delta;

        // Are the longitudes either side of the point’s meridian (lambda),
        // and are the latitudes smaller than the parallel (phi)?
        if (antimeridian ^ lambda0 >= lambda ^ lambda1 >= lambda) {
          var arc = cartesianCross(cartesian(point0), cartesian(point1));
          cartesianNormalizeInPlace(arc);
          var intersection = cartesianCross(normal, arc);
          cartesianNormalizeInPlace(intersection);
          var phiArc = (antimeridian ^ delta >= 0 ? -1 : 1) * asin$1(intersection[2]);
          if (phi > phiArc || phi === phiArc && (arc[0] || arc[1])) {
            winding += antimeridian ^ delta >= 0 ? 1 : -1;
          }
        }
      }
    }

    // First, determine whether the South pole is inside or outside:
    //
    // It is inside if:
    // * the polygon winds around it in a clockwise direction.
    // * the polygon does not (cumulatively) wind around it, but has a negative
    //   (counter-clockwise) area.
    //
    // Second, count the (signed) number of times a segment crosses a lambda
    // from the point to the South pole.  If it is zero, then the point is the
    // same side as the South pole.

    return (angle < -epsilon$5 || angle < epsilon$5 && sum < -1e-12) ^ (winding & 1);
  }

  function clip(pointVisible, clipLine, interpolate, start) {
    return function(sink) {
      var line = clipLine(sink),
          ringBuffer = clipBuffer(),
          ringSink = clipLine(ringBuffer),
          polygonStarted = false,
          polygon,
          segments,
          ring;

      var clip = {
        point: point,
        lineStart: lineStart,
        lineEnd: lineEnd,
        polygonStart: function() {
          clip.point = pointRing;
          clip.lineStart = ringStart;
          clip.lineEnd = ringEnd;
          segments = [];
          polygon = [];
        },
        polygonEnd: function() {
          clip.point = point;
          clip.lineStart = lineStart;
          clip.lineEnd = lineEnd;
          segments = merge(segments);
          var startInside = polygonContains(polygon, start);
          if (segments.length) {
            if (!polygonStarted) sink.polygonStart(), polygonStarted = true;
            clipRejoin(segments, compareIntersection, startInside, interpolate, sink);
          } else if (startInside) {
            if (!polygonStarted) sink.polygonStart(), polygonStarted = true;
            sink.lineStart();
            interpolate(null, null, 1, sink);
            sink.lineEnd();
          }
          if (polygonStarted) sink.polygonEnd(), polygonStarted = false;
          segments = polygon = null;
        },
        sphere: function() {
          sink.polygonStart();
          sink.lineStart();
          interpolate(null, null, 1, sink);
          sink.lineEnd();
          sink.polygonEnd();
        }
      };

      function point(lambda, phi) {
        if (pointVisible(lambda, phi)) sink.point(lambda, phi);
      }

      function pointLine(lambda, phi) {
        line.point(lambda, phi);
      }

      function lineStart() {
        clip.point = pointLine;
        line.lineStart();
      }

      function lineEnd() {
        clip.point = point;
        line.lineEnd();
      }

      function pointRing(lambda, phi) {
        ring.push([lambda, phi]);
        ringSink.point(lambda, phi);
      }

      function ringStart() {
        ringSink.lineStart();
        ring = [];
      }

      function ringEnd() {
        pointRing(ring[0][0], ring[0][1]);
        ringSink.lineEnd();

        var clean = ringSink.clean(),
            ringSegments = ringBuffer.result(),
            i, n = ringSegments.length, m,
            segment,
            point;

        ring.pop();
        polygon.push(ring);
        ring = null;

        if (!n) return;

        // No intersections.
        if (clean & 1) {
          segment = ringSegments[0];
          if ((m = segment.length - 1) > 0) {
            if (!polygonStarted) sink.polygonStart(), polygonStarted = true;
            sink.lineStart();
            for (i = 0; i < m; ++i) sink.point((point = segment[i])[0], point[1]);
            sink.lineEnd();
          }
          return;
        }

        // Rejoin connected segments.
        // TODO reuse ringBuffer.rejoin()?
        if (n > 1 && clean & 2) ringSegments.push(ringSegments.pop().concat(ringSegments.shift()));

        segments.push(ringSegments.filter(validSegment));
      }

      return clip;
    };
  }

  function validSegment(segment) {
    return segment.length > 1;
  }

  // Intersections are sorted along the clip edge. For both antimeridian cutting
  // and circle clipping, the same comparison is used.
  function compareIntersection(a, b) {
    return ((a = a.x)[0] < 0 ? a[1] - halfPi$2 - epsilon$5 : halfPi$2 - a[1])
         - ((b = b.x)[0] < 0 ? b[1] - halfPi$2 - epsilon$5 : halfPi$2 - b[1]);
  }

  var clipAntimeridian = clip(
    function() { return true; },
    clipAntimeridianLine,
    clipAntimeridianInterpolate,
    [-pi$4, -halfPi$2]
  );

  // Takes a line and cuts into visible segments. Return values: 0 - there were
  // intersections or the line was empty; 1 - no intersections; 2 - there were
  // intersections, and the first and last segments should be rejoined.
  function clipAntimeridianLine(stream) {
    var lambda0 = NaN,
        phi0 = NaN,
        sign0 = NaN,
        clean; // no intersections

    return {
      lineStart: function() {
        stream.lineStart();
        clean = 1;
      },
      point: function(lambda1, phi1) {
        var sign1 = lambda1 > 0 ? pi$4 : -pi$4,
            delta = abs$3(lambda1 - lambda0);
        if (abs$3(delta - pi$4) < epsilon$5) { // line crosses a pole
          stream.point(lambda0, phi0 = (phi0 + phi1) / 2 > 0 ? halfPi$2 : -halfPi$2);
          stream.point(sign0, phi0);
          stream.lineEnd();
          stream.lineStart();
          stream.point(sign1, phi0);
          stream.point(lambda1, phi0);
          clean = 0;
        } else if (sign0 !== sign1 && delta >= pi$4) { // line crosses antimeridian
          if (abs$3(lambda0 - sign0) < epsilon$5) lambda0 -= sign0 * epsilon$5; // handle degeneracies
          if (abs$3(lambda1 - sign1) < epsilon$5) lambda1 -= sign1 * epsilon$5;
          phi0 = clipAntimeridianIntersect(lambda0, phi0, lambda1, phi1);
          stream.point(sign0, phi0);
          stream.lineEnd();
          stream.lineStart();
          stream.point(sign1, phi0);
          clean = 0;
        }
        stream.point(lambda0 = lambda1, phi0 = phi1);
        sign0 = sign1;
      },
      lineEnd: function() {
        stream.lineEnd();
        lambda0 = phi0 = NaN;
      },
      clean: function() {
        return 2 - clean; // if intersections, rejoin first and last segments
      }
    };
  }

  function clipAntimeridianIntersect(lambda0, phi0, lambda1, phi1) {
    var cosPhi0,
        cosPhi1,
        sinLambda0Lambda1 = sin$2(lambda0 - lambda1);
    return abs$3(sinLambda0Lambda1) > epsilon$5
        ? atan((sin$2(phi0) * (cosPhi1 = cos$2(phi1)) * sin$2(lambda1)
            - sin$2(phi1) * (cosPhi0 = cos$2(phi0)) * sin$2(lambda0))
            / (cosPhi0 * cosPhi1 * sinLambda0Lambda1))
        : (phi0 + phi1) / 2;
  }

  function clipAntimeridianInterpolate(from, to, direction, stream) {
    var phi;
    if (from == null) {
      phi = direction * halfPi$2;
      stream.point(-pi$4, phi);
      stream.point(0, phi);
      stream.point(pi$4, phi);
      stream.point(pi$4, 0);
      stream.point(pi$4, -phi);
      stream.point(0, -phi);
      stream.point(-pi$4, -phi);
      stream.point(-pi$4, 0);
      stream.point(-pi$4, phi);
    } else if (abs$3(from[0] - to[0]) > epsilon$5) {
      var lambda = from[0] < to[0] ? pi$4 : -pi$4;
      phi = direction * lambda / 2;
      stream.point(-lambda, phi);
      stream.point(0, phi);
      stream.point(lambda, phi);
    } else {
      stream.point(to[0], to[1]);
    }
  }

  function clipCircle(radius) {
    var cr = cos$2(radius),
        delta = 2 * radians,
        smallRadius = cr > 0,
        notHemisphere = abs$3(cr) > epsilon$5; // TODO optimise for this common case

    function interpolate(from, to, direction, stream) {
      circleStream(stream, radius, delta, direction, from, to);
    }

    function visible(lambda, phi) {
      return cos$2(lambda) * cos$2(phi) > cr;
    }

    // Takes a line and cuts into visible segments. Return values used for polygon
    // clipping: 0 - there were intersections or the line was empty; 1 - no
    // intersections 2 - there were intersections, and the first and last segments
    // should be rejoined.
    function clipLine(stream) {
      var point0, // previous point
          c0, // code for previous point
          v0, // visibility of previous point
          v00, // visibility of first point
          clean; // no intersections
      return {
        lineStart: function() {
          v00 = v0 = false;
          clean = 1;
        },
        point: function(lambda, phi) {
          var point1 = [lambda, phi],
              point2,
              v = visible(lambda, phi),
              c = smallRadius
                ? v ? 0 : code(lambda, phi)
                : v ? code(lambda + (lambda < 0 ? pi$4 : -pi$4), phi) : 0;
          if (!point0 && (v00 = v0 = v)) stream.lineStart();
          if (v !== v0) {
            point2 = intersect(point0, point1);
            if (!point2 || pointEqual(point0, point2) || pointEqual(point1, point2))
              point1[2] = 1;
          }
          if (v !== v0) {
            clean = 0;
            if (v) {
              // outside going in
              stream.lineStart();
              point2 = intersect(point1, point0);
              stream.point(point2[0], point2[1]);
            } else {
              // inside going out
              point2 = intersect(point0, point1);
              stream.point(point2[0], point2[1], 2);
              stream.lineEnd();
            }
            point0 = point2;
          } else if (notHemisphere && point0 && smallRadius ^ v) {
            var t;
            // If the codes for two points are different, or are both zero,
            // and there this segment intersects with the small circle.
            if (!(c & c0) && (t = intersect(point1, point0, true))) {
              clean = 0;
              if (smallRadius) {
                stream.lineStart();
                stream.point(t[0][0], t[0][1]);
                stream.point(t[1][0], t[1][1]);
                stream.lineEnd();
              } else {
                stream.point(t[1][0], t[1][1]);
                stream.lineEnd();
                stream.lineStart();
                stream.point(t[0][0], t[0][1], 3);
              }
            }
          }
          if (v && (!point0 || !pointEqual(point0, point1))) {
            stream.point(point1[0], point1[1]);
          }
          point0 = point1, v0 = v, c0 = c;
        },
        lineEnd: function() {
          if (v0) stream.lineEnd();
          point0 = null;
        },
        // Rejoin first and last segments if there were intersections and the first
        // and last points were visible.
        clean: function() {
          return clean | ((v00 && v0) << 1);
        }
      };
    }

    // Intersects the great circle between a and b with the clip circle.
    function intersect(a, b, two) {
      var pa = cartesian(a),
          pb = cartesian(b);

      // We have two planes, n1.p = d1 and n2.p = d2.
      // Find intersection line p(t) = c1 n1 + c2 n2 + t (n1 ⨯ n2).
      var n1 = [1, 0, 0], // normal
          n2 = cartesianCross(pa, pb),
          n2n2 = cartesianDot(n2, n2),
          n1n2 = n2[0], // cartesianDot(n1, n2),
          determinant = n2n2 - n1n2 * n1n2;

      // Two polar points.
      if (!determinant) return !two && a;

      var c1 =  cr * n2n2 / determinant,
          c2 = -cr * n1n2 / determinant,
          n1xn2 = cartesianCross(n1, n2),
          A = cartesianScale(n1, c1),
          B = cartesianScale(n2, c2);
      cartesianAddInPlace(A, B);

      // Solve |p(t)|^2 = 1.
      var u = n1xn2,
          w = cartesianDot(A, u),
          uu = cartesianDot(u, u),
          t2 = w * w - uu * (cartesianDot(A, A) - 1);

      if (t2 < 0) return;

      var t = sqrt$1(t2),
          q = cartesianScale(u, (-w - t) / uu);
      cartesianAddInPlace(q, A);
      q = spherical(q);

      if (!two) return q;

      // Two intersection points.
      var lambda0 = a[0],
          lambda1 = b[0],
          phi0 = a[1],
          phi1 = b[1],
          z;

      if (lambda1 < lambda0) z = lambda0, lambda0 = lambda1, lambda1 = z;

      var delta = lambda1 - lambda0,
          polar = abs$3(delta - pi$4) < epsilon$5,
          meridian = polar || delta < epsilon$5;

      if (!polar && phi1 < phi0) z = phi0, phi0 = phi1, phi1 = z;

      // Check that the first point is between a and b.
      if (meridian
          ? polar
            ? phi0 + phi1 > 0 ^ q[1] < (abs$3(q[0] - lambda0) < epsilon$5 ? phi0 : phi1)
            : phi0 <= q[1] && q[1] <= phi1
          : delta > pi$4 ^ (lambda0 <= q[0] && q[0] <= lambda1)) {
        var q1 = cartesianScale(u, (-w + t) / uu);
        cartesianAddInPlace(q1, A);
        return [q, spherical(q1)];
      }
    }

    // Generates a 4-bit vector representing the location of a point relative to
    // the small circle's bounding box.
    function code(lambda, phi) {
      var r = smallRadius ? radius : pi$4 - radius,
          code = 0;
      if (lambda < -r) code |= 1; // left
      else if (lambda > r) code |= 2; // right
      if (phi < -r) code |= 4; // below
      else if (phi > r) code |= 8; // above
      return code;
    }

    return clip(visible, clipLine, interpolate, smallRadius ? [0, -radius] : [-pi$4, radius - pi$4]);
  }

  function clipLine(a, b, x0, y0, x1, y1) {
    var ax = a[0],
        ay = a[1],
        bx = b[0],
        by = b[1],
        t0 = 0,
        t1 = 1,
        dx = bx - ax,
        dy = by - ay,
        r;

    r = x0 - ax;
    if (!dx && r > 0) return;
    r /= dx;
    if (dx < 0) {
      if (r < t0) return;
      if (r < t1) t1 = r;
    } else if (dx > 0) {
      if (r > t1) return;
      if (r > t0) t0 = r;
    }

    r = x1 - ax;
    if (!dx && r < 0) return;
    r /= dx;
    if (dx < 0) {
      if (r > t1) return;
      if (r > t0) t0 = r;
    } else if (dx > 0) {
      if (r < t0) return;
      if (r < t1) t1 = r;
    }

    r = y0 - ay;
    if (!dy && r > 0) return;
    r /= dy;
    if (dy < 0) {
      if (r < t0) return;
      if (r < t1) t1 = r;
    } else if (dy > 0) {
      if (r > t1) return;
      if (r > t0) t0 = r;
    }

    r = y1 - ay;
    if (!dy && r < 0) return;
    r /= dy;
    if (dy < 0) {
      if (r > t1) return;
      if (r > t0) t0 = r;
    } else if (dy > 0) {
      if (r < t0) return;
      if (r < t1) t1 = r;
    }

    if (t0 > 0) a[0] = ax + t0 * dx, a[1] = ay + t0 * dy;
    if (t1 < 1) b[0] = ax + t1 * dx, b[1] = ay + t1 * dy;
    return true;
  }

  var clipMax = 1e9, clipMin = -clipMax;

  // TODO Use d3-polygon’s polygonContains here for the ring check?
  // TODO Eliminate duplicate buffering in clipBuffer and polygon.push?

  function clipRectangle(x0, y0, x1, y1) {

    function visible(x, y) {
      return x0 <= x && x <= x1 && y0 <= y && y <= y1;
    }

    function interpolate(from, to, direction, stream) {
      var a = 0, a1 = 0;
      if (from == null
          || (a = corner(from, direction)) !== (a1 = corner(to, direction))
          || comparePoint(from, to) < 0 ^ direction > 0) {
        do stream.point(a === 0 || a === 3 ? x0 : x1, a > 1 ? y1 : y0);
        while ((a = (a + direction + 4) % 4) !== a1);
      } else {
        stream.point(to[0], to[1]);
      }
    }

    function corner(p, direction) {
      return abs$3(p[0] - x0) < epsilon$5 ? direction > 0 ? 0 : 3
          : abs$3(p[0] - x1) < epsilon$5 ? direction > 0 ? 2 : 1
          : abs$3(p[1] - y0) < epsilon$5 ? direction > 0 ? 1 : 0
          : direction > 0 ? 3 : 2; // abs(p[1] - y1) < epsilon
    }

    function compareIntersection(a, b) {
      return comparePoint(a.x, b.x);
    }

    function comparePoint(a, b) {
      var ca = corner(a, 1),
          cb = corner(b, 1);
      return ca !== cb ? ca - cb
          : ca === 0 ? b[1] - a[1]
          : ca === 1 ? a[0] - b[0]
          : ca === 2 ? a[1] - b[1]
          : b[0] - a[0];
    }

    return function(stream) {
      var activeStream = stream,
          bufferStream = clipBuffer(),
          segments,
          polygon,
          ring,
          x__, y__, v__, // first point
          x_, y_, v_, // previous point
          first,
          clean;

      var clipStream = {
        point: point,
        lineStart: lineStart,
        lineEnd: lineEnd,
        polygonStart: polygonStart,
        polygonEnd: polygonEnd
      };

      function point(x, y) {
        if (visible(x, y)) activeStream.point(x, y);
      }

      function polygonInside() {
        var winding = 0;

        for (var i = 0, n = polygon.length; i < n; ++i) {
          for (var ring = polygon[i], j = 1, m = ring.length, point = ring[0], a0, a1, b0 = point[0], b1 = point[1]; j < m; ++j) {
            a0 = b0, a1 = b1, point = ring[j], b0 = point[0], b1 = point[1];
            if (a1 <= y1) { if (b1 > y1 && (b0 - a0) * (y1 - a1) > (b1 - a1) * (x0 - a0)) ++winding; }
            else { if (b1 <= y1 && (b0 - a0) * (y1 - a1) < (b1 - a1) * (x0 - a0)) --winding; }
          }
        }

        return winding;
      }

      // Buffer geometry within a polygon and then clip it en masse.
      function polygonStart() {
        activeStream = bufferStream, segments = [], polygon = [], clean = true;
      }

      function polygonEnd() {
        var startInside = polygonInside(),
            cleanInside = clean && startInside,
            visible = (segments = merge(segments)).length;
        if (cleanInside || visible) {
          stream.polygonStart();
          if (cleanInside) {
            stream.lineStart();
            interpolate(null, null, 1, stream);
            stream.lineEnd();
          }
          if (visible) {
            clipRejoin(segments, compareIntersection, startInside, interpolate, stream);
          }
          stream.polygonEnd();
        }
        activeStream = stream, segments = polygon = ring = null;
      }

      function lineStart() {
        clipStream.point = linePoint;
        if (polygon) polygon.push(ring = []);
        first = true;
        v_ = false;
        x_ = y_ = NaN;
      }

      // TODO rather than special-case polygons, simply handle them separately.
      // Ideally, coincident intersection points should be jittered to avoid
      // clipping issues.
      function lineEnd() {
        if (segments) {
          linePoint(x__, y__);
          if (v__ && v_) bufferStream.rejoin();
          segments.push(bufferStream.result());
        }
        clipStream.point = point;
        if (v_) activeStream.lineEnd();
      }

      function linePoint(x, y) {
        var v = visible(x, y);
        if (polygon) ring.push([x, y]);
        if (first) {
          x__ = x, y__ = y, v__ = v;
          first = false;
          if (v) {
            activeStream.lineStart();
            activeStream.point(x, y);
          }
        } else {
          if (v && v_) activeStream.point(x, y);
          else {
            var a = [x_ = Math.max(clipMin, Math.min(clipMax, x_)), y_ = Math.max(clipMin, Math.min(clipMax, y_))],
                b = [x = Math.max(clipMin, Math.min(clipMax, x)), y = Math.max(clipMin, Math.min(clipMax, y))];
            if (clipLine(a, b, x0, y0, x1, y1)) {
              if (!v_) {
                activeStream.lineStart();
                activeStream.point(a[0], a[1]);
              }
              activeStream.point(b[0], b[1]);
              if (!v) activeStream.lineEnd();
              clean = false;
            } else if (v) {
              activeStream.lineStart();
              activeStream.point(x, y);
              clean = false;
            }
          }
        }
        x_ = x, y_ = y, v_ = v;
      }

      return clipStream;
    };
  }

  var identity$5 = x => x;

  var areaSum = new Adder(),
      areaRingSum = new Adder(),
      x00$2,
      y00$2,
      x0$3,
      y0$3;

  var areaStream = {
    point: noop$2,
    lineStart: noop$2,
    lineEnd: noop$2,
    polygonStart: function() {
      areaStream.lineStart = areaRingStart;
      areaStream.lineEnd = areaRingEnd;
    },
    polygonEnd: function() {
      areaStream.lineStart = areaStream.lineEnd = areaStream.point = noop$2;
      areaSum.add(abs$3(areaRingSum));
      areaRingSum = new Adder();
    },
    result: function() {
      var area = areaSum / 2;
      areaSum = new Adder();
      return area;
    }
  };

  function areaRingStart() {
    areaStream.point = areaPointFirst;
  }

  function areaPointFirst(x, y) {
    areaStream.point = areaPoint;
    x00$2 = x0$3 = x, y00$2 = y0$3 = y;
  }

  function areaPoint(x, y) {
    areaRingSum.add(y0$3 * x - x0$3 * y);
    x0$3 = x, y0$3 = y;
  }

  function areaRingEnd() {
    areaPoint(x00$2, y00$2);
  }

  var x0$2 = Infinity,
      y0$2 = x0$2,
      x1 = -x0$2,
      y1 = x1;

  var boundsStream = {
    point: boundsPoint,
    lineStart: noop$2,
    lineEnd: noop$2,
    polygonStart: noop$2,
    polygonEnd: noop$2,
    result: function() {
      var bounds = [[x0$2, y0$2], [x1, y1]];
      x1 = y1 = -(y0$2 = x0$2 = Infinity);
      return bounds;
    }
  };

  function boundsPoint(x, y) {
    if (x < x0$2) x0$2 = x;
    if (x > x1) x1 = x;
    if (y < y0$2) y0$2 = y;
    if (y > y1) y1 = y;
  }

  // TODO Enforce positive area for exterior, negative area for interior?

  var X0 = 0,
      Y0 = 0,
      Z0 = 0,
      X1 = 0,
      Y1 = 0,
      Z1 = 0,
      X2 = 0,
      Y2 = 0,
      Z2 = 0,
      x00$1,
      y00$1,
      x0$1,
      y0$1;

  var centroidStream = {
    point: centroidPoint,
    lineStart: centroidLineStart,
    lineEnd: centroidLineEnd,
    polygonStart: function() {
      centroidStream.lineStart = centroidRingStart;
      centroidStream.lineEnd = centroidRingEnd;
    },
    polygonEnd: function() {
      centroidStream.point = centroidPoint;
      centroidStream.lineStart = centroidLineStart;
      centroidStream.lineEnd = centroidLineEnd;
    },
    result: function() {
      var centroid = Z2 ? [X2 / Z2, Y2 / Z2]
          : Z1 ? [X1 / Z1, Y1 / Z1]
          : Z0 ? [X0 / Z0, Y0 / Z0]
          : [NaN, NaN];
      X0 = Y0 = Z0 =
      X1 = Y1 = Z1 =
      X2 = Y2 = Z2 = 0;
      return centroid;
    }
  };

  function centroidPoint(x, y) {
    X0 += x;
    Y0 += y;
    ++Z0;
  }

  function centroidLineStart() {
    centroidStream.point = centroidPointFirstLine;
  }

  function centroidPointFirstLine(x, y) {
    centroidStream.point = centroidPointLine;
    centroidPoint(x0$1 = x, y0$1 = y);
  }

  function centroidPointLine(x, y) {
    var dx = x - x0$1, dy = y - y0$1, z = sqrt$1(dx * dx + dy * dy);
    X1 += z * (x0$1 + x) / 2;
    Y1 += z * (y0$1 + y) / 2;
    Z1 += z;
    centroidPoint(x0$1 = x, y0$1 = y);
  }

  function centroidLineEnd() {
    centroidStream.point = centroidPoint;
  }

  function centroidRingStart() {
    centroidStream.point = centroidPointFirstRing;
  }

  function centroidRingEnd() {
    centroidPointRing(x00$1, y00$1);
  }

  function centroidPointFirstRing(x, y) {
    centroidStream.point = centroidPointRing;
    centroidPoint(x00$1 = x0$1 = x, y00$1 = y0$1 = y);
  }

  function centroidPointRing(x, y) {
    var dx = x - x0$1,
        dy = y - y0$1,
        z = sqrt$1(dx * dx + dy * dy);

    X1 += z * (x0$1 + x) / 2;
    Y1 += z * (y0$1 + y) / 2;
    Z1 += z;

    z = y0$1 * x - x0$1 * y;
    X2 += z * (x0$1 + x);
    Y2 += z * (y0$1 + y);
    Z2 += z * 3;
    centroidPoint(x0$1 = x, y0$1 = y);
  }

  function PathContext(context) {
    this._context = context;
  }

  PathContext.prototype = {
    _radius: 4.5,
    pointRadius: function(_) {
      return this._radius = _, this;
    },
    polygonStart: function() {
      this._line = 0;
    },
    polygonEnd: function() {
      this._line = NaN;
    },
    lineStart: function() {
      this._point = 0;
    },
    lineEnd: function() {
      if (this._line === 0) this._context.closePath();
      this._point = NaN;
    },
    point: function(x, y) {
      switch (this._point) {
        case 0: {
          this._context.moveTo(x, y);
          this._point = 1;
          break;
        }
        case 1: {
          this._context.lineTo(x, y);
          break;
        }
        default: {
          this._context.moveTo(x + this._radius, y);
          this._context.arc(x, y, this._radius, 0, tau$4);
          break;
        }
      }
    },
    result: noop$2
  };

  var lengthSum = new Adder(),
      lengthRing,
      x00,
      y00,
      x0,
      y0;

  var lengthStream = {
    point: noop$2,
    lineStart: function() {
      lengthStream.point = lengthPointFirst;
    },
    lineEnd: function() {
      if (lengthRing) lengthPoint(x00, y00);
      lengthStream.point = noop$2;
    },
    polygonStart: function() {
      lengthRing = true;
    },
    polygonEnd: function() {
      lengthRing = null;
    },
    result: function() {
      var length = +lengthSum;
      lengthSum = new Adder();
      return length;
    }
  };

  function lengthPointFirst(x, y) {
    lengthStream.point = lengthPoint;
    x00 = x0 = x, y00 = y0 = y;
  }

  function lengthPoint(x, y) {
    x0 -= x, y0 -= y;
    lengthSum.add(sqrt$1(x0 * x0 + y0 * y0));
    x0 = x, y0 = y;
  }

  // Simple caching for constant-radius points.
  let cacheDigits, cacheAppend, cacheRadius, cacheCircle;

  class PathString {
    constructor(digits) {
      this._append = digits == null ? append$2 : appendRound$2(digits);
      this._radius = 4.5;
      this._ = "";
    }
    pointRadius(_) {
      this._radius = +_;
      return this;
    }
    polygonStart() {
      this._line = 0;
    }
    polygonEnd() {
      this._line = NaN;
    }
    lineStart() {
      this._point = 0;
    }
    lineEnd() {
      if (this._line === 0) this._ += "Z";
      this._point = NaN;
    }
    point(x, y) {
      switch (this._point) {
        case 0: {
          this._append`M${x},${y}`;
          this._point = 1;
          break;
        }
        case 1: {
          this._append`L${x},${y}`;
          break;
        }
        default: {
          this._append`M${x},${y}`;
          if (this._radius !== cacheRadius || this._append !== cacheAppend) {
            const r = this._radius;
            const s = this._;
            this._ = ""; // stash the old string so we can cache the circle path fragment
            this._append`m0,${r}a${r},${r} 0 1,1 0,${ -2 * r}a${r},${r} 0 1,1 0,${2 * r}z`;
            cacheRadius = r;
            cacheAppend = this._append;
            cacheCircle = this._;
            this._ = s;
          }
          this._ += cacheCircle;
          break;
        }
      }
    }
    result() {
      const result = this._;
      this._ = "";
      return result.length ? result : null;
    }
  }

  function append$2(strings) {
    let i = 1;
    this._ += strings[0];
    for (const j = strings.length; i < j; ++i) {
      this._ += arguments[i] + strings[i];
    }
  }

  function appendRound$2(digits) {
    const d = Math.floor(digits);
    if (!(d >= 0)) throw new RangeError(`invalid digits: ${digits}`);
    if (d > 15) return append$2;
    if (d !== cacheDigits) {
      const k = 10 ** d;
      cacheDigits = d;
      cacheAppend = function append(strings) {
        let i = 1;
        this._ += strings[0];
        for (const j = strings.length; i < j; ++i) {
          this._ += Math.round(arguments[i] * k) / k + strings[i];
        }
      };
    }
    return cacheAppend;
  }

  function geoPath(projection, context) {
    let digits = 3,
        pointRadius = 4.5,
        projectionStream,
        contextStream;

    function path(object) {
      if (object) {
        if (typeof pointRadius === "function") contextStream.pointRadius(+pointRadius.apply(this, arguments));
        geoStream(object, projectionStream(contextStream));
      }
      return contextStream.result();
    }

    path.area = function(object) {
      geoStream(object, projectionStream(areaStream));
      return areaStream.result();
    };

    path.measure = function(object) {
      geoStream(object, projectionStream(lengthStream));
      return lengthStream.result();
    };

    path.bounds = function(object) {
      geoStream(object, projectionStream(boundsStream));
      return boundsStream.result();
    };

    path.centroid = function(object) {
      geoStream(object, projectionStream(centroidStream));
      return centroidStream.result();
    };

    path.projection = function(_) {
      if (!arguments.length) return projection;
      projectionStream = _ == null ? (projection = null, identity$5) : (projection = _).stream;
      return path;
    };

    path.context = function(_) {
      if (!arguments.length) return context;
      contextStream = _ == null ? (context = null, new PathString(digits)) : new PathContext(context = _);
      if (typeof pointRadius !== "function") contextStream.pointRadius(pointRadius);
      return path;
    };

    path.pointRadius = function(_) {
      if (!arguments.length) return pointRadius;
      pointRadius = typeof _ === "function" ? _ : (contextStream.pointRadius(+_), +_);
      return path;
    };

    path.digits = function(_) {
      if (!arguments.length) return digits;
      if (_ == null) digits = null;
      else {
        const d = Math.floor(_);
        if (!(d >= 0)) throw new RangeError(`invalid digits: ${_}`);
        digits = d;
      }
      if (context === null) contextStream = new PathString(digits);
      return path;
    };

    return path.projection(projection).digits(digits).context(context);
  }

  function transformer$2(methods) {
    return function(stream) {
      var s = new TransformStream;
      for (var key in methods) s[key] = methods[key];
      s.stream = stream;
      return s;
    };
  }

  function TransformStream() {}

  TransformStream.prototype = {
    constructor: TransformStream,
    point: function(x, y) { this.stream.point(x, y); },
    sphere: function() { this.stream.sphere(); },
    lineStart: function() { this.stream.lineStart(); },
    lineEnd: function() { this.stream.lineEnd(); },
    polygonStart: function() { this.stream.polygonStart(); },
    polygonEnd: function() { this.stream.polygonEnd(); }
  };

  function fit(projection, fitBounds, object) {
    var clip = projection.clipExtent && projection.clipExtent();
    projection.scale(150).translate([0, 0]);
    if (clip != null) projection.clipExtent(null);
    geoStream(object, projection.stream(boundsStream));
    fitBounds(boundsStream.result());
    if (clip != null) projection.clipExtent(clip);
    return projection;
  }

  function fitExtent(projection, extent, object) {
    return fit(projection, function(b) {
      var w = extent[1][0] - extent[0][0],
          h = extent[1][1] - extent[0][1],
          k = Math.min(w / (b[1][0] - b[0][0]), h / (b[1][1] - b[0][1])),
          x = +extent[0][0] + (w - k * (b[1][0] + b[0][0])) / 2,
          y = +extent[0][1] + (h - k * (b[1][1] + b[0][1])) / 2;
      projection.scale(150 * k).translate([x, y]);
    }, object);
  }

  function fitSize(projection, size, object) {
    return fitExtent(projection, [[0, 0], size], object);
  }

  function fitWidth(projection, width, object) {
    return fit(projection, function(b) {
      var w = +width,
          k = w / (b[1][0] - b[0][0]),
          x = (w - k * (b[1][0] + b[0][0])) / 2,
          y = -k * b[0][1];
      projection.scale(150 * k).translate([x, y]);
    }, object);
  }

  function fitHeight(projection, height, object) {
    return fit(projection, function(b) {
      var h = +height,
          k = h / (b[1][1] - b[0][1]),
          x = -k * b[0][0],
          y = (h - k * (b[1][1] + b[0][1])) / 2;
      projection.scale(150 * k).translate([x, y]);
    }, object);
  }

  var maxDepth = 16, // maximum depth of subdivision
      cosMinDistance = cos$2(30 * radians); // cos(minimum angular distance)

  function resample(project, delta2) {
    return +delta2 ? resample$1(project, delta2) : resampleNone(project);
  }

  function resampleNone(project) {
    return transformer$2({
      point: function(x, y) {
        x = project(x, y);
        this.stream.point(x[0], x[1]);
      }
    });
  }

  function resample$1(project, delta2) {

    function resampleLineTo(x0, y0, lambda0, a0, b0, c0, x1, y1, lambda1, a1, b1, c1, depth, stream) {
      var dx = x1 - x0,
          dy = y1 - y0,
          d2 = dx * dx + dy * dy;
      if (d2 > 4 * delta2 && depth--) {
        var a = a0 + a1,
            b = b0 + b1,
            c = c0 + c1,
            m = sqrt$1(a * a + b * b + c * c),
            phi2 = asin$1(c /= m),
            lambda2 = abs$3(abs$3(c) - 1) < epsilon$5 || abs$3(lambda0 - lambda1) < epsilon$5 ? (lambda0 + lambda1) / 2 : atan2$1(b, a),
            p = project(lambda2, phi2),
            x2 = p[0],
            y2 = p[1],
            dx2 = x2 - x0,
            dy2 = y2 - y0,
            dz = dy * dx2 - dx * dy2;
        if (dz * dz / d2 > delta2 // perpendicular projected distance
            || abs$3((dx * dx2 + dy * dy2) / d2 - 0.5) > 0.3 // midpoint close to an end
            || a0 * a1 + b0 * b1 + c0 * c1 < cosMinDistance) { // angular distance
          resampleLineTo(x0, y0, lambda0, a0, b0, c0, x2, y2, lambda2, a /= m, b /= m, c, depth, stream);
          stream.point(x2, y2);
          resampleLineTo(x2, y2, lambda2, a, b, c, x1, y1, lambda1, a1, b1, c1, depth, stream);
        }
      }
    }
    return function(stream) {
      var lambda00, x00, y00, a00, b00, c00, // first point
          lambda0, x0, y0, a0, b0, c0; // previous point

      var resampleStream = {
        point: point,
        lineStart: lineStart,
        lineEnd: lineEnd,
        polygonStart: function() { stream.polygonStart(); resampleStream.lineStart = ringStart; },
        polygonEnd: function() { stream.polygonEnd(); resampleStream.lineStart = lineStart; }
      };

      function point(x, y) {
        x = project(x, y);
        stream.point(x[0], x[1]);
      }

      function lineStart() {
        x0 = NaN;
        resampleStream.point = linePoint;
        stream.lineStart();
      }

      function linePoint(lambda, phi) {
        var c = cartesian([lambda, phi]), p = project(lambda, phi);
        resampleLineTo(x0, y0, lambda0, a0, b0, c0, x0 = p[0], y0 = p[1], lambda0 = lambda, a0 = c[0], b0 = c[1], c0 = c[2], maxDepth, stream);
        stream.point(x0, y0);
      }

      function lineEnd() {
        resampleStream.point = point;
        stream.lineEnd();
      }

      function ringStart() {
        lineStart();
        resampleStream.point = ringPoint;
        resampleStream.lineEnd = ringEnd;
      }

      function ringPoint(lambda, phi) {
        linePoint(lambda00 = lambda, phi), x00 = x0, y00 = y0, a00 = a0, b00 = b0, c00 = c0;
        resampleStream.point = linePoint;
      }

      function ringEnd() {
        resampleLineTo(x0, y0, lambda0, a0, b0, c0, x00, y00, lambda00, a00, b00, c00, maxDepth, stream);
        resampleStream.lineEnd = lineEnd;
        lineEnd();
      }

      return resampleStream;
    };
  }

  var transformRadians = transformer$2({
    point: function(x, y) {
      this.stream.point(x * radians, y * radians);
    }
  });

  function transformRotate(rotate) {
    return transformer$2({
      point: function(x, y) {
        var r = rotate(x, y);
        return this.stream.point(r[0], r[1]);
      }
    });
  }

  function scaleTranslate(k, dx, dy, sx, sy) {
    function transform(x, y) {
      x *= sx; y *= sy;
      return [dx + k * x, dy - k * y];
    }
    transform.invert = function(x, y) {
      return [(x - dx) / k * sx, (dy - y) / k * sy];
    };
    return transform;
  }

  function scaleTranslateRotate(k, dx, dy, sx, sy, alpha) {
    if (!alpha) return scaleTranslate(k, dx, dy, sx, sy);
    var cosAlpha = cos$2(alpha),
        sinAlpha = sin$2(alpha),
        a = cosAlpha * k,
        b = sinAlpha * k,
        ai = cosAlpha / k,
        bi = sinAlpha / k,
        ci = (sinAlpha * dy - cosAlpha * dx) / k,
        fi = (sinAlpha * dx + cosAlpha * dy) / k;
    function transform(x, y) {
      x *= sx; y *= sy;
      return [a * x - b * y + dx, dy - b * x - a * y];
    }
    transform.invert = function(x, y) {
      return [sx * (ai * x - bi * y + ci), sy * (fi - bi * x - ai * y)];
    };
    return transform;
  }

  function projection(project) {
    return projectionMutator(function() { return project; })();
  }

  function projectionMutator(projectAt) {
    var project,
        k = 150, // scale
        x = 480, y = 250, // translate
        lambda = 0, phi = 0, // center
        deltaLambda = 0, deltaPhi = 0, deltaGamma = 0, rotate, // pre-rotate
        alpha = 0, // post-rotate angle
        sx = 1, // reflectX
        sy = 1, // reflectX
        theta = null, preclip = clipAntimeridian, // pre-clip angle
        x0 = null, y0, x1, y1, postclip = identity$5, // post-clip extent
        delta2 = 0.5, // precision
        projectResample,
        projectTransform,
        projectRotateTransform,
        cache,
        cacheStream;

    function projection(point) {
      return projectRotateTransform(point[0] * radians, point[1] * radians);
    }

    function invert(point) {
      point = projectRotateTransform.invert(point[0], point[1]);
      return point && [point[0] * degrees$1, point[1] * degrees$1];
    }

    projection.stream = function(stream) {
      return cache && cacheStream === stream ? cache : cache = transformRadians(transformRotate(rotate)(preclip(projectResample(postclip(cacheStream = stream)))));
    };

    projection.preclip = function(_) {
      return arguments.length ? (preclip = _, theta = undefined, reset()) : preclip;
    };

    projection.postclip = function(_) {
      return arguments.length ? (postclip = _, x0 = y0 = x1 = y1 = null, reset()) : postclip;
    };

    projection.clipAngle = function(_) {
      return arguments.length ? (preclip = +_ ? clipCircle(theta = _ * radians) : (theta = null, clipAntimeridian), reset()) : theta * degrees$1;
    };

    projection.clipExtent = function(_) {
      return arguments.length ? (postclip = _ == null ? (x0 = y0 = x1 = y1 = null, identity$5) : clipRectangle(x0 = +_[0][0], y0 = +_[0][1], x1 = +_[1][0], y1 = +_[1][1]), reset()) : x0 == null ? null : [[x0, y0], [x1, y1]];
    };

    projection.scale = function(_) {
      return arguments.length ? (k = +_, recenter()) : k;
    };

    projection.translate = function(_) {
      return arguments.length ? (x = +_[0], y = +_[1], recenter()) : [x, y];
    };

    projection.center = function(_) {
      return arguments.length ? (lambda = _[0] % 360 * radians, phi = _[1] % 360 * radians, recenter()) : [lambda * degrees$1, phi * degrees$1];
    };

    projection.rotate = function(_) {
      return arguments.length ? (deltaLambda = _[0] % 360 * radians, deltaPhi = _[1] % 360 * radians, deltaGamma = _.length > 2 ? _[2] % 360 * radians : 0, recenter()) : [deltaLambda * degrees$1, deltaPhi * degrees$1, deltaGamma * degrees$1];
    };

    projection.angle = function(_) {
      return arguments.length ? (alpha = _ % 360 * radians, recenter()) : alpha * degrees$1;
    };

    projection.reflectX = function(_) {
      return arguments.length ? (sx = _ ? -1 : 1, recenter()) : sx < 0;
    };

    projection.reflectY = function(_) {
      return arguments.length ? (sy = _ ? -1 : 1, recenter()) : sy < 0;
    };

    projection.precision = function(_) {
      return arguments.length ? (projectResample = resample(projectTransform, delta2 = _ * _), reset()) : sqrt$1(delta2);
    };

    projection.fitExtent = function(extent, object) {
      return fitExtent(projection, extent, object);
    };

    projection.fitSize = function(size, object) {
      return fitSize(projection, size, object);
    };

    projection.fitWidth = function(width, object) {
      return fitWidth(projection, width, object);
    };

    projection.fitHeight = function(height, object) {
      return fitHeight(projection, height, object);
    };

    function recenter() {
      var center = scaleTranslateRotate(k, 0, 0, sx, sy, alpha).apply(null, project(lambda, phi)),
          transform = scaleTranslateRotate(k, x - center[0], y - center[1], sx, sy, alpha);
      rotate = rotateRadians(deltaLambda, deltaPhi, deltaGamma);
      projectTransform = compose(project, transform);
      projectRotateTransform = compose(rotate, projectTransform);
      projectResample = resample(projectTransform, delta2);
      return reset();
    }

    function reset() {
      cache = cacheStream = null;
      return projection;
    }

    return function() {
      project = projectAt.apply(this, arguments);
      projection.invert = project.invert && invert;
      return recenter();
    };
  }

  function mercatorRaw(lambda, phi) {
    return [lambda, log(tan((halfPi$2 + phi) / 2))];
  }

  mercatorRaw.invert = function(x, y) {
    return [x, 2 * atan(exp(y)) - halfPi$2];
  };

  function geoMercator() {
    return mercatorProjection(mercatorRaw)
        .scale(961 / tau$4);
  }

  function mercatorProjection(project) {
    var m = projection(project),
        center = m.center,
        scale = m.scale,
        translate = m.translate,
        clipExtent = m.clipExtent,
        x0 = null, y0, x1, y1; // clip extent

    m.scale = function(_) {
      return arguments.length ? (scale(_), reclip()) : scale();
    };

    m.translate = function(_) {
      return arguments.length ? (translate(_), reclip()) : translate();
    };

    m.center = function(_) {
      return arguments.length ? (center(_), reclip()) : center();
    };

    m.clipExtent = function(_) {
      return arguments.length ? ((_ == null ? x0 = y0 = x1 = y1 = null : (x0 = +_[0][0], y0 = +_[0][1], x1 = +_[1][0], y1 = +_[1][1])), reclip()) : x0 == null ? null : [[x0, y0], [x1, y1]];
    };

    function reclip() {
      var k = pi$4 * scale(),
          t = m(rotation(m.rotate()).invert([0, 0]));
      return clipExtent(x0 == null
          ? [[t[0] - k, t[1] - k], [t[0] + k, t[1] + k]] : project === mercatorRaw
          ? [[Math.max(t[0] - k, x0), y0], [Math.min(t[0] + k, x1), y1]]
          : [[x0, Math.max(t[1] - k, y0)], [x1, Math.min(t[1] + k, y1)]]);
    }

    return reclip();
  }

  function equirectangularRaw(lambda, phi) {
    return [lambda, phi];
  }

  equirectangularRaw.invert = equirectangularRaw;

  function geoEquirectangular() {
    return projection(equirectangularRaw)
        .scale(152.63);
  }

  function initRange(domain, range) {
    switch (arguments.length) {
      case 0: break;
      case 1: this.range(domain); break;
      default: this.range(range).domain(domain); break;
    }
    return this;
  }

  function initInterpolator(domain, interpolator) {
    switch (arguments.length) {
      case 0: break;
      case 1: {
        if (typeof domain === "function") this.interpolator(domain);
        else this.range(domain);
        break;
      }
      default: {
        this.domain(domain);
        if (typeof interpolator === "function") this.interpolator(interpolator);
        else this.range(interpolator);
        break;
      }
    }
    return this;
  }

  const implicit = Symbol("implicit");

  function ordinal() {
    var index = new InternMap(),
        domain = [],
        range = [],
        unknown = implicit;

    function scale(d) {
      let i = index.get(d);
      if (i === undefined) {
        if (unknown !== implicit) return unknown;
        index.set(d, i = domain.push(d) - 1);
      }
      return range[i % range.length];
    }

    scale.domain = function(_) {
      if (!arguments.length) return domain.slice();
      domain = [], index = new InternMap();
      for (const value of _) {
        if (index.has(value)) continue;
        index.set(value, domain.push(value) - 1);
      }
      return scale;
    };

    scale.range = function(_) {
      return arguments.length ? (range = Array.from(_), scale) : range.slice();
    };

    scale.unknown = function(_) {
      return arguments.length ? (unknown = _, scale) : unknown;
    };

    scale.copy = function() {
      return ordinal(domain, range).unknown(unknown);
    };

    initRange.apply(scale, arguments);

    return scale;
  }

  function band() {
    var scale = ordinal().unknown(undefined),
        domain = scale.domain,
        ordinalRange = scale.range,
        r0 = 0,
        r1 = 1,
        step,
        bandwidth,
        round = false,
        paddingInner = 0,
        paddingOuter = 0,
        align = 0.5;

    delete scale.unknown;

    function rescale() {
      var n = domain().length,
          reverse = r1 < r0,
          start = reverse ? r1 : r0,
          stop = reverse ? r0 : r1;
      step = (stop - start) / Math.max(1, n - paddingInner + paddingOuter * 2);
      if (round) step = Math.floor(step);
      start += (stop - start - step * (n - paddingInner)) * align;
      bandwidth = step * (1 - paddingInner);
      if (round) start = Math.round(start), bandwidth = Math.round(bandwidth);
      var values = range$1(n).map(function(i) { return start + step * i; });
      return ordinalRange(reverse ? values.reverse() : values);
    }

    scale.domain = function(_) {
      return arguments.length ? (domain(_), rescale()) : domain();
    };

    scale.range = function(_) {
      return arguments.length ? ([r0, r1] = _, r0 = +r0, r1 = +r1, rescale()) : [r0, r1];
    };

    scale.rangeRound = function(_) {
      return [r0, r1] = _, r0 = +r0, r1 = +r1, round = true, rescale();
    };

    scale.bandwidth = function() {
      return bandwidth;
    };

    scale.step = function() {
      return step;
    };

    scale.round = function(_) {
      return arguments.length ? (round = !!_, rescale()) : round;
    };

    scale.padding = function(_) {
      return arguments.length ? (paddingInner = Math.min(1, paddingOuter = +_), rescale()) : paddingInner;
    };

    scale.paddingInner = function(_) {
      return arguments.length ? (paddingInner = Math.min(1, _), rescale()) : paddingInner;
    };

    scale.paddingOuter = function(_) {
      return arguments.length ? (paddingOuter = +_, rescale()) : paddingOuter;
    };

    scale.align = function(_) {
      return arguments.length ? (align = Math.max(0, Math.min(1, _)), rescale()) : align;
    };

    scale.copy = function() {
      return band(domain(), [r0, r1])
          .round(round)
          .paddingInner(paddingInner)
          .paddingOuter(paddingOuter)
          .align(align);
    };

    return initRange.apply(rescale(), arguments);
  }

  function pointish(scale) {
    var copy = scale.copy;

    scale.padding = scale.paddingOuter;
    delete scale.paddingInner;
    delete scale.paddingOuter;

    scale.copy = function() {
      return pointish(copy());
    };

    return scale;
  }

  function point$2() {
    return pointish(band.apply(null, arguments).paddingInner(1));
  }

  function define$1(constructor, factory, prototype) {
    constructor.prototype = factory.prototype = prototype;
    prototype.constructor = constructor;
  }

  function extend$1(parent, definition) {
    var prototype = Object.create(parent.prototype);
    for (var key in definition) prototype[key] = definition[key];
    return prototype;
  }

  function Color$1() {}

  var darker$1 = 0.7;
  var brighter$1 = 1 / darker$1;

  var reI$1 = "\\s*([+-]?\\d+)\\s*",
      reN$1 = "\\s*([+-]?(?:\\d*\\.)?\\d+(?:[eE][+-]?\\d+)?)\\s*",
      reP$1 = "\\s*([+-]?(?:\\d*\\.)?\\d+(?:[eE][+-]?\\d+)?)%\\s*",
      reHex$1 = /^#([0-9a-f]{3,8})$/,
      reRgbInteger$1 = new RegExp(`^rgb\\(${reI$1},${reI$1},${reI$1}\\)$`),
      reRgbPercent$1 = new RegExp(`^rgb\\(${reP$1},${reP$1},${reP$1}\\)$`),
      reRgbaInteger$1 = new RegExp(`^rgba\\(${reI$1},${reI$1},${reI$1},${reN$1}\\)$`),
      reRgbaPercent$1 = new RegExp(`^rgba\\(${reP$1},${reP$1},${reP$1},${reN$1}\\)$`),
      reHslPercent$1 = new RegExp(`^hsl\\(${reN$1},${reP$1},${reP$1}\\)$`),
      reHslaPercent$1 = new RegExp(`^hsla\\(${reN$1},${reP$1},${reP$1},${reN$1}\\)$`);

  var named$1 = {
    aliceblue: 0xf0f8ff,
    antiquewhite: 0xfaebd7,
    aqua: 0x00ffff,
    aquamarine: 0x7fffd4,
    azure: 0xf0ffff,
    beige: 0xf5f5dc,
    bisque: 0xffe4c4,
    black: 0x000000,
    blanchedalmond: 0xffebcd,
    blue: 0x0000ff,
    blueviolet: 0x8a2be2,
    brown: 0xa52a2a,
    burlywood: 0xdeb887,
    cadetblue: 0x5f9ea0,
    chartreuse: 0x7fff00,
    chocolate: 0xd2691e,
    coral: 0xff7f50,
    cornflowerblue: 0x6495ed,
    cornsilk: 0xfff8dc,
    crimson: 0xdc143c,
    cyan: 0x00ffff,
    darkblue: 0x00008b,
    darkcyan: 0x008b8b,
    darkgoldenrod: 0xb8860b,
    darkgray: 0xa9a9a9,
    darkgreen: 0x006400,
    darkgrey: 0xa9a9a9,
    darkkhaki: 0xbdb76b,
    darkmagenta: 0x8b008b,
    darkolivegreen: 0x556b2f,
    darkorange: 0xff8c00,
    darkorchid: 0x9932cc,
    darkred: 0x8b0000,
    darksalmon: 0xe9967a,
    darkseagreen: 0x8fbc8f,
    darkslateblue: 0x483d8b,
    darkslategray: 0x2f4f4f,
    darkslategrey: 0x2f4f4f,
    darkturquoise: 0x00ced1,
    darkviolet: 0x9400d3,
    deeppink: 0xff1493,
    deepskyblue: 0x00bfff,
    dimgray: 0x696969,
    dimgrey: 0x696969,
    dodgerblue: 0x1e90ff,
    firebrick: 0xb22222,
    floralwhite: 0xfffaf0,
    forestgreen: 0x228b22,
    fuchsia: 0xff00ff,
    gainsboro: 0xdcdcdc,
    ghostwhite: 0xf8f8ff,
    gold: 0xffd700,
    goldenrod: 0xdaa520,
    gray: 0x808080,
    green: 0x008000,
    greenyellow: 0xadff2f,
    grey: 0x808080,
    honeydew: 0xf0fff0,
    hotpink: 0xff69b4,
    indianred: 0xcd5c5c,
    indigo: 0x4b0082,
    ivory: 0xfffff0,
    khaki: 0xf0e68c,
    lavender: 0xe6e6fa,
    lavenderblush: 0xfff0f5,
    lawngreen: 0x7cfc00,
    lemonchiffon: 0xfffacd,
    lightblue: 0xadd8e6,
    lightcoral: 0xf08080,
    lightcyan: 0xe0ffff,
    lightgoldenrodyellow: 0xfafad2,
    lightgray: 0xd3d3d3,
    lightgreen: 0x90ee90,
    lightgrey: 0xd3d3d3,
    lightpink: 0xffb6c1,
    lightsalmon: 0xffa07a,
    lightseagreen: 0x20b2aa,
    lightskyblue: 0x87cefa,
    lightslategray: 0x778899,
    lightslategrey: 0x778899,
    lightsteelblue: 0xb0c4de,
    lightyellow: 0xffffe0,
    lime: 0x00ff00,
    limegreen: 0x32cd32,
    linen: 0xfaf0e6,
    magenta: 0xff00ff,
    maroon: 0x800000,
    mediumaquamarine: 0x66cdaa,
    mediumblue: 0x0000cd,
    mediumorchid: 0xba55d3,
    mediumpurple: 0x9370db,
    mediumseagreen: 0x3cb371,
    mediumslateblue: 0x7b68ee,
    mediumspringgreen: 0x00fa9a,
    mediumturquoise: 0x48d1cc,
    mediumvioletred: 0xc71585,
    midnightblue: 0x191970,
    mintcream: 0xf5fffa,
    mistyrose: 0xffe4e1,
    moccasin: 0xffe4b5,
    navajowhite: 0xffdead,
    navy: 0x000080,
    oldlace: 0xfdf5e6,
    olive: 0x808000,
    olivedrab: 0x6b8e23,
    orange: 0xffa500,
    orangered: 0xff4500,
    orchid: 0xda70d6,
    palegoldenrod: 0xeee8aa,
    palegreen: 0x98fb98,
    paleturquoise: 0xafeeee,
    palevioletred: 0xdb7093,
    papayawhip: 0xffefd5,
    peachpuff: 0xffdab9,
    peru: 0xcd853f,
    pink: 0xffc0cb,
    plum: 0xdda0dd,
    powderblue: 0xb0e0e6,
    purple: 0x800080,
    rebeccapurple: 0x663399,
    red: 0xff0000,
    rosybrown: 0xbc8f8f,
    royalblue: 0x4169e1,
    saddlebrown: 0x8b4513,
    salmon: 0xfa8072,
    sandybrown: 0xf4a460,
    seagreen: 0x2e8b57,
    seashell: 0xfff5ee,
    sienna: 0xa0522d,
    silver: 0xc0c0c0,
    skyblue: 0x87ceeb,
    slateblue: 0x6a5acd,
    slategray: 0x708090,
    slategrey: 0x708090,
    snow: 0xfffafa,
    springgreen: 0x00ff7f,
    steelblue: 0x4682b4,
    tan: 0xd2b48c,
    teal: 0x008080,
    thistle: 0xd8bfd8,
    tomato: 0xff6347,
    turquoise: 0x40e0d0,
    violet: 0xee82ee,
    wheat: 0xf5deb3,
    white: 0xffffff,
    whitesmoke: 0xf5f5f5,
    yellow: 0xffff00,
    yellowgreen: 0x9acd32
  };

  define$1(Color$1, color$1, {
    copy(channels) {
      return Object.assign(new this.constructor, this, channels);
    },
    displayable() {
      return this.rgb().displayable();
    },
    hex: color_formatHex$1, // Deprecated! Use color.formatHex.
    formatHex: color_formatHex$1,
    formatHex8: color_formatHex8$1,
    formatHsl: color_formatHsl$1,
    formatRgb: color_formatRgb$1,
    toString: color_formatRgb$1
  });

  function color_formatHex$1() {
    return this.rgb().formatHex();
  }

  function color_formatHex8$1() {
    return this.rgb().formatHex8();
  }

  function color_formatHsl$1() {
    return hslConvert$1(this).formatHsl();
  }

  function color_formatRgb$1() {
    return this.rgb().formatRgb();
  }

  function color$1(format) {
    var m, l;
    format = (format + "").trim().toLowerCase();
    return (m = reHex$1.exec(format)) ? (l = m[1].length, m = parseInt(m[1], 16), l === 6 ? rgbn$1(m) // #ff0000
        : l === 3 ? new Rgb$1((m >> 8 & 0xf) | (m >> 4 & 0xf0), (m >> 4 & 0xf) | (m & 0xf0), ((m & 0xf) << 4) | (m & 0xf), 1) // #f00
        : l === 8 ? rgba$1(m >> 24 & 0xff, m >> 16 & 0xff, m >> 8 & 0xff, (m & 0xff) / 0xff) // #ff000000
        : l === 4 ? rgba$1((m >> 12 & 0xf) | (m >> 8 & 0xf0), (m >> 8 & 0xf) | (m >> 4 & 0xf0), (m >> 4 & 0xf) | (m & 0xf0), (((m & 0xf) << 4) | (m & 0xf)) / 0xff) // #f000
        : null) // invalid hex
        : (m = reRgbInteger$1.exec(format)) ? new Rgb$1(m[1], m[2], m[3], 1) // rgb(255, 0, 0)
        : (m = reRgbPercent$1.exec(format)) ? new Rgb$1(m[1] * 255 / 100, m[2] * 255 / 100, m[3] * 255 / 100, 1) // rgb(100%, 0%, 0%)
        : (m = reRgbaInteger$1.exec(format)) ? rgba$1(m[1], m[2], m[3], m[4]) // rgba(255, 0, 0, 1)
        : (m = reRgbaPercent$1.exec(format)) ? rgba$1(m[1] * 255 / 100, m[2] * 255 / 100, m[3] * 255 / 100, m[4]) // rgb(100%, 0%, 0%, 1)
        : (m = reHslPercent$1.exec(format)) ? hsla$1(m[1], m[2] / 100, m[3] / 100, 1) // hsl(120, 50%, 50%)
        : (m = reHslaPercent$1.exec(format)) ? hsla$1(m[1], m[2] / 100, m[3] / 100, m[4]) // hsla(120, 50%, 50%, 1)
        : named$1.hasOwnProperty(format) ? rgbn$1(named$1[format]) // eslint-disable-line no-prototype-builtins
        : format === "transparent" ? new Rgb$1(NaN, NaN, NaN, 0)
        : null;
  }

  function rgbn$1(n) {
    return new Rgb$1(n >> 16 & 0xff, n >> 8 & 0xff, n & 0xff, 1);
  }

  function rgba$1(r, g, b, a) {
    if (a <= 0) r = g = b = NaN;
    return new Rgb$1(r, g, b, a);
  }

  function rgbConvert$1(o) {
    if (!(o instanceof Color$1)) o = color$1(o);
    if (!o) return new Rgb$1;
    o = o.rgb();
    return new Rgb$1(o.r, o.g, o.b, o.opacity);
  }

  function rgb$1(r, g, b, opacity) {
    return arguments.length === 1 ? rgbConvert$1(r) : new Rgb$1(r, g, b, opacity == null ? 1 : opacity);
  }

  function Rgb$1(r, g, b, opacity) {
    this.r = +r;
    this.g = +g;
    this.b = +b;
    this.opacity = +opacity;
  }

  define$1(Rgb$1, rgb$1, extend$1(Color$1, {
    brighter(k) {
      k = k == null ? brighter$1 : Math.pow(brighter$1, k);
      return new Rgb$1(this.r * k, this.g * k, this.b * k, this.opacity);
    },
    darker(k) {
      k = k == null ? darker$1 : Math.pow(darker$1, k);
      return new Rgb$1(this.r * k, this.g * k, this.b * k, this.opacity);
    },
    rgb() {
      return this;
    },
    clamp() {
      return new Rgb$1(clampi$1(this.r), clampi$1(this.g), clampi$1(this.b), clampa$1(this.opacity));
    },
    displayable() {
      return (-0.5 <= this.r && this.r < 255.5)
          && (-0.5 <= this.g && this.g < 255.5)
          && (-0.5 <= this.b && this.b < 255.5)
          && (0 <= this.opacity && this.opacity <= 1);
    },
    hex: rgb_formatHex$1, // Deprecated! Use color.formatHex.
    formatHex: rgb_formatHex$1,
    formatHex8: rgb_formatHex8$1,
    formatRgb: rgb_formatRgb$1,
    toString: rgb_formatRgb$1
  }));

  function rgb_formatHex$1() {
    return `#${hex$1(this.r)}${hex$1(this.g)}${hex$1(this.b)}`;
  }

  function rgb_formatHex8$1() {
    return `#${hex$1(this.r)}${hex$1(this.g)}${hex$1(this.b)}${hex$1((isNaN(this.opacity) ? 1 : this.opacity) * 255)}`;
  }

  function rgb_formatRgb$1() {
    const a = clampa$1(this.opacity);
    return `${a === 1 ? "rgb(" : "rgba("}${clampi$1(this.r)}, ${clampi$1(this.g)}, ${clampi$1(this.b)}${a === 1 ? ")" : `, ${a})`}`;
  }

  function clampa$1(opacity) {
    return isNaN(opacity) ? 1 : Math.max(0, Math.min(1, opacity));
  }

  function clampi$1(value) {
    return Math.max(0, Math.min(255, Math.round(value) || 0));
  }

  function hex$1(value) {
    value = clampi$1(value);
    return (value < 16 ? "0" : "") + value.toString(16);
  }

  function hsla$1(h, s, l, a) {
    if (a <= 0) h = s = l = NaN;
    else if (l <= 0 || l >= 1) h = s = NaN;
    else if (s <= 0) h = NaN;
    return new Hsl$1(h, s, l, a);
  }

  function hslConvert$1(o) {
    if (o instanceof Hsl$1) return new Hsl$1(o.h, o.s, o.l, o.opacity);
    if (!(o instanceof Color$1)) o = color$1(o);
    if (!o) return new Hsl$1;
    if (o instanceof Hsl$1) return o;
    o = o.rgb();
    var r = o.r / 255,
        g = o.g / 255,
        b = o.b / 255,
        min = Math.min(r, g, b),
        max = Math.max(r, g, b),
        h = NaN,
        s = max - min,
        l = (max + min) / 2;
    if (s) {
      if (r === max) h = (g - b) / s + (g < b) * 6;
      else if (g === max) h = (b - r) / s + 2;
      else h = (r - g) / s + 4;
      s /= l < 0.5 ? max + min : 2 - max - min;
      h *= 60;
    } else {
      s = l > 0 && l < 1 ? 0 : h;
    }
    return new Hsl$1(h, s, l, o.opacity);
  }

  function hsl$1(h, s, l, opacity) {
    return arguments.length === 1 ? hslConvert$1(h) : new Hsl$1(h, s, l, opacity == null ? 1 : opacity);
  }

  function Hsl$1(h, s, l, opacity) {
    this.h = +h;
    this.s = +s;
    this.l = +l;
    this.opacity = +opacity;
  }

  define$1(Hsl$1, hsl$1, extend$1(Color$1, {
    brighter(k) {
      k = k == null ? brighter$1 : Math.pow(brighter$1, k);
      return new Hsl$1(this.h, this.s, this.l * k, this.opacity);
    },
    darker(k) {
      k = k == null ? darker$1 : Math.pow(darker$1, k);
      return new Hsl$1(this.h, this.s, this.l * k, this.opacity);
    },
    rgb() {
      var h = this.h % 360 + (this.h < 0) * 360,
          s = isNaN(h) || isNaN(this.s) ? 0 : this.s,
          l = this.l,
          m2 = l + (l < 0.5 ? l : 1 - l) * s,
          m1 = 2 * l - m2;
      return new Rgb$1(
        hsl2rgb$1(h >= 240 ? h - 240 : h + 120, m1, m2),
        hsl2rgb$1(h, m1, m2),
        hsl2rgb$1(h < 120 ? h + 240 : h - 120, m1, m2),
        this.opacity
      );
    },
    clamp() {
      return new Hsl$1(clamph$1(this.h), clampt$1(this.s), clampt$1(this.l), clampa$1(this.opacity));
    },
    displayable() {
      return (0 <= this.s && this.s <= 1 || isNaN(this.s))
          && (0 <= this.l && this.l <= 1)
          && (0 <= this.opacity && this.opacity <= 1);
    },
    formatHsl() {
      const a = clampa$1(this.opacity);
      return `${a === 1 ? "hsl(" : "hsla("}${clamph$1(this.h)}, ${clampt$1(this.s) * 100}%, ${clampt$1(this.l) * 100}%${a === 1 ? ")" : `, ${a})`}`;
    }
  }));

  function clamph$1(value) {
    value = (value || 0) % 360;
    return value < 0 ? value + 360 : value;
  }

  function clampt$1(value) {
    return Math.max(0, Math.min(1, value || 0));
  }

  /* From FvD 13.37, CSS Color Module Level 3 */
  function hsl2rgb$1(h, m1, m2) {
    return (h < 60 ? m1 + (m2 - m1) * h / 60
        : h < 180 ? m2
        : h < 240 ? m1 + (m2 - m1) * (240 - h) / 60
        : m1) * 255;
  }

  function basis(t1, v0, v1, v2, v3) {
    var t2 = t1 * t1, t3 = t2 * t1;
    return ((1 - 3 * t1 + 3 * t2 - t3) * v0
        + (4 - 6 * t2 + 3 * t3) * v1
        + (1 + 3 * t1 + 3 * t2 - 3 * t3) * v2
        + t3 * v3) / 6;
  }

  function basis$1(values) {
    var n = values.length - 1;
    return function(t) {
      var i = t <= 0 ? (t = 0) : t >= 1 ? (t = 1, n - 1) : Math.floor(t * n),
          v1 = values[i],
          v2 = values[i + 1],
          v0 = i > 0 ? values[i - 1] : 2 * v1 - v2,
          v3 = i < n - 1 ? values[i + 2] : 2 * v2 - v1;
      return basis((t - i / n) * n, v0, v1, v2, v3);
    };
  }

  var constant$6 = x => () => x;

  function linear$2(a, d) {
    return function(t) {
      return a + t * d;
    };
  }

  function exponential$1(a, b, y) {
    return a = Math.pow(a, y), b = Math.pow(b, y) - a, y = 1 / y, function(t) {
      return Math.pow(a + t * b, y);
    };
  }

  function gamma$1(y) {
    return (y = +y) === 1 ? nogamma$1 : function(a, b) {
      return b - a ? exponential$1(a, b, y) : constant$6(isNaN(a) ? b : a);
    };
  }

  function nogamma$1(a, b) {
    var d = b - a;
    return d ? linear$2(a, d) : constant$6(isNaN(a) ? b : a);
  }

  var interpolateRgb$1 = (function rgbGamma(y) {
    var color = gamma$1(y);

    function rgb(start, end) {
      var r = color((start = rgb$1(start)).r, (end = rgb$1(end)).r),
          g = color(start.g, end.g),
          b = color(start.b, end.b),
          opacity = nogamma$1(start.opacity, end.opacity);
      return function(t) {
        start.r = r(t);
        start.g = g(t);
        start.b = b(t);
        start.opacity = opacity(t);
        return start + "";
      };
    }

    rgb.gamma = rgbGamma;

    return rgb;
  })(1);

  function rgbSpline(spline) {
    return function(colors) {
      var n = colors.length,
          r = new Array(n),
          g = new Array(n),
          b = new Array(n),
          i, color;
      for (i = 0; i < n; ++i) {
        color = rgb$1(colors[i]);
        r[i] = color.r || 0;
        g[i] = color.g || 0;
        b[i] = color.b || 0;
      }
      r = spline(r);
      g = spline(g);
      b = spline(b);
      color.opacity = 1;
      return function(t) {
        color.r = r(t);
        color.g = g(t);
        color.b = b(t);
        return color + "";
      };
    };
  }

  var rgbBasis = rgbSpline(basis$1);

  function numberArray$1(a, b) {
    if (!b) b = [];
    var n = a ? Math.min(b.length, a.length) : 0,
        c = b.slice(),
        i;
    return function(t) {
      for (i = 0; i < n; ++i) c[i] = a[i] * (1 - t) + b[i] * t;
      return c;
    };
  }

  function isNumberArray$1(x) {
    return ArrayBuffer.isView(x) && !(x instanceof DataView);
  }

  function genericArray$1(a, b) {
    var nb = b ? b.length : 0,
        na = a ? Math.min(nb, a.length) : 0,
        x = new Array(na),
        c = new Array(nb),
        i;

    for (i = 0; i < na; ++i) x[i] = interpolate$2(a[i], b[i]);
    for (; i < nb; ++i) c[i] = b[i];

    return function(t) {
      for (i = 0; i < na; ++i) c[i] = x[i](t);
      return c;
    };
  }

  function date$1(a, b) {
    var d = new Date;
    return a = +a, b = +b, function(t) {
      return d.setTime(a * (1 - t) + b * t), d;
    };
  }

  function interpolateNumber(a, b) {
    return a = +a, b = +b, function(t) {
      return a * (1 - t) + b * t;
    };
  }

  function object$1(a, b) {
    var i = {},
        c = {},
        k;

    if (a === null || typeof a !== "object") a = {};
    if (b === null || typeof b !== "object") b = {};

    for (k in b) {
      if (k in a) {
        i[k] = interpolate$2(a[k], b[k]);
      } else {
        c[k] = b[k];
      }
    }

    return function(t) {
      for (k in i) c[k] = i[k](t);
      return c;
    };
  }

  var reA$1 = /[-+]?(?:\d+\.?\d*|\.?\d+)(?:[eE][-+]?\d+)?/g,
      reB$1 = new RegExp(reA$1.source, "g");

  function zero$1(b) {
    return function() {
      return b;
    };
  }

  function one$1(b) {
    return function(t) {
      return b(t) + "";
    };
  }

  function interpolateString(a, b) {
    var bi = reA$1.lastIndex = reB$1.lastIndex = 0, // scan index for next number in b
        am, // current match in a
        bm, // current match in b
        bs, // string preceding current number in b, if any
        i = -1, // index in s
        s = [], // string constants and placeholders
        q = []; // number interpolators

    // Coerce inputs to strings.
    a = a + "", b = b + "";

    // Interpolate pairs of numbers in a & b.
    while ((am = reA$1.exec(a))
        && (bm = reB$1.exec(b))) {
      if ((bs = bm.index) > bi) { // a string precedes the next number in b
        bs = b.slice(bi, bs);
        if (s[i]) s[i] += bs; // coalesce with previous string
        else s[++i] = bs;
      }
      if ((am = am[0]) === (bm = bm[0])) { // numbers in a & b match
        if (s[i]) s[i] += bm; // coalesce with previous string
        else s[++i] = bm;
      } else { // interpolate non-matching numbers
        s[++i] = null;
        q.push({i: i, x: interpolateNumber(am, bm)});
      }
      bi = reB$1.lastIndex;
    }

    // Add remains of b.
    if (bi < b.length) {
      bs = b.slice(bi);
      if (s[i]) s[i] += bs; // coalesce with previous string
      else s[++i] = bs;
    }

    // Special optimization for only a single match.
    // Otherwise, interpolate each of the numbers and rejoin the string.
    return s.length < 2 ? (q[0]
        ? one$1(q[0].x)
        : zero$1(b))
        : (b = q.length, function(t) {
            for (var i = 0, o; i < b; ++i) s[(o = q[i]).i] = o.x(t);
            return s.join("");
          });
  }

  function interpolate$2(a, b) {
    var t = typeof b, c;
    return b == null || t === "boolean" ? constant$6(b)
        : (t === "number" ? interpolateNumber
        : t === "string" ? ((c = color$1(b)) ? (b = c, interpolateRgb$1) : interpolateString)
        : b instanceof color$1 ? interpolateRgb$1
        : b instanceof Date ? date$1
        : isNumberArray$1(b) ? numberArray$1
        : Array.isArray(b) ? genericArray$1
        : typeof b.valueOf !== "function" && typeof b.toString !== "function" || isNaN(b) ? object$1
        : interpolateNumber)(a, b);
  }

  function interpolateRound(a, b) {
    return a = +a, b = +b, function(t) {
      return Math.round(a * (1 - t) + b * t);
    };
  }

  var degrees = 180 / Math.PI;

  var identity$4 = {
    translateX: 0,
    translateY: 0,
    rotate: 0,
    skewX: 0,
    scaleX: 1,
    scaleY: 1
  };

  function decompose(a, b, c, d, e, f) {
    var scaleX, scaleY, skewX;
    if (scaleX = Math.sqrt(a * a + b * b)) a /= scaleX, b /= scaleX;
    if (skewX = a * c + b * d) c -= a * skewX, d -= b * skewX;
    if (scaleY = Math.sqrt(c * c + d * d)) c /= scaleY, d /= scaleY, skewX /= scaleY;
    if (a * d < b * c) a = -a, b = -b, skewX = -skewX, scaleX = -scaleX;
    return {
      translateX: e,
      translateY: f,
      rotate: Math.atan2(b, a) * degrees,
      skewX: Math.atan(skewX) * degrees,
      scaleX: scaleX,
      scaleY: scaleY
    };
  }

  var svgNode;

  /* eslint-disable no-undef */
  function parseCss(value) {
    const m = new (typeof DOMMatrix === "function" ? DOMMatrix : WebKitCSSMatrix)(value + "");
    return m.isIdentity ? identity$4 : decompose(m.a, m.b, m.c, m.d, m.e, m.f);
  }

  function parseSvg(value) {
    if (value == null) return identity$4;
    if (!svgNode) svgNode = document.createElementNS("http://www.w3.org/2000/svg", "g");
    svgNode.setAttribute("transform", value);
    if (!(value = svgNode.transform.baseVal.consolidate())) return identity$4;
    value = value.matrix;
    return decompose(value.a, value.b, value.c, value.d, value.e, value.f);
  }

  function interpolateTransform(parse, pxComma, pxParen, degParen) {

    function pop(s) {
      return s.length ? s.pop() + " " : "";
    }

    function translate(xa, ya, xb, yb, s, q) {
      if (xa !== xb || ya !== yb) {
        var i = s.push("translate(", null, pxComma, null, pxParen);
        q.push({i: i - 4, x: interpolateNumber(xa, xb)}, {i: i - 2, x: interpolateNumber(ya, yb)});
      } else if (xb || yb) {
        s.push("translate(" + xb + pxComma + yb + pxParen);
      }
    }

    function rotate(a, b, s, q) {
      if (a !== b) {
        if (a - b > 180) b += 360; else if (b - a > 180) a += 360; // shortest path
        q.push({i: s.push(pop(s) + "rotate(", null, degParen) - 2, x: interpolateNumber(a, b)});
      } else if (b) {
        s.push(pop(s) + "rotate(" + b + degParen);
      }
    }

    function skewX(a, b, s, q) {
      if (a !== b) {
        q.push({i: s.push(pop(s) + "skewX(", null, degParen) - 2, x: interpolateNumber(a, b)});
      } else if (b) {
        s.push(pop(s) + "skewX(" + b + degParen);
      }
    }

    function scale(xa, ya, xb, yb, s, q) {
      if (xa !== xb || ya !== yb) {
        var i = s.push(pop(s) + "scale(", null, ",", null, ")");
        q.push({i: i - 4, x: interpolateNumber(xa, xb)}, {i: i - 2, x: interpolateNumber(ya, yb)});
      } else if (xb !== 1 || yb !== 1) {
        s.push(pop(s) + "scale(" + xb + "," + yb + ")");
      }
    }

    return function(a, b) {
      var s = [], // string constants and placeholders
          q = []; // number interpolators
      a = parse(a), b = parse(b);
      translate(a.translateX, a.translateY, b.translateX, b.translateY, s, q);
      rotate(a.rotate, b.rotate, s, q);
      skewX(a.skewX, b.skewX, s, q);
      scale(a.scaleX, a.scaleY, b.scaleX, b.scaleY, s, q);
      a = b = null; // gc
      return function(t) {
        var i = -1, n = q.length, o;
        while (++i < n) s[(o = q[i]).i] = o.x(t);
        return s.join("");
      };
    };
  }

  var interpolateTransformCss = interpolateTransform(parseCss, "px, ", "px)", "deg)");
  var interpolateTransformSvg = interpolateTransform(parseSvg, ", ", ")", ")");

  function constants(x) {
    return function() {
      return x;
    };
  }

  function number$2(x) {
    return +x;
  }

  var unit = [0, 1];

  function identity$3(x) {
    return x;
  }

  function normalize(a, b) {
    return (b -= (a = +a))
        ? function(x) { return (x - a) / b; }
        : constants(isNaN(b) ? NaN : 0.5);
  }

  function clamper(a, b) {
    var t;
    if (a > b) t = a, a = b, b = t;
    return function(x) { return Math.max(a, Math.min(b, x)); };
  }

  // normalize(a, b)(x) takes a domain value x in [a,b] and returns the corresponding parameter t in [0,1].
  // interpolate(a, b)(t) takes a parameter t in [0,1] and returns the corresponding range value x in [a,b].
  function bimap(domain, range, interpolate) {
    var d0 = domain[0], d1 = domain[1], r0 = range[0], r1 = range[1];
    if (d1 < d0) d0 = normalize(d1, d0), r0 = interpolate(r1, r0);
    else d0 = normalize(d0, d1), r0 = interpolate(r0, r1);
    return function(x) { return r0(d0(x)); };
  }

  function polymap(domain, range, interpolate) {
    var j = Math.min(domain.length, range.length) - 1,
        d = new Array(j),
        r = new Array(j),
        i = -1;

    // Reverse descending domains.
    if (domain[j] < domain[0]) {
      domain = domain.slice().reverse();
      range = range.slice().reverse();
    }

    while (++i < j) {
      d[i] = normalize(domain[i], domain[i + 1]);
      r[i] = interpolate(range[i], range[i + 1]);
    }

    return function(x) {
      var i = bisectRight(domain, x, 1, j) - 1;
      return r[i](d[i](x));
    };
  }

  function copy$1(source, target) {
    return target
        .domain(source.domain())
        .range(source.range())
        .interpolate(source.interpolate())
        .clamp(source.clamp())
        .unknown(source.unknown());
  }

  function transformer$1() {
    var domain = unit,
        range = unit,
        interpolate = interpolate$2,
        transform,
        untransform,
        unknown,
        clamp = identity$3,
        piecewise,
        output,
        input;

    function rescale() {
      var n = Math.min(domain.length, range.length);
      if (clamp !== identity$3) clamp = clamper(domain[0], domain[n - 1]);
      piecewise = n > 2 ? polymap : bimap;
      output = input = null;
      return scale;
    }

    function scale(x) {
      return x == null || isNaN(x = +x) ? unknown : (output || (output = piecewise(domain.map(transform), range, interpolate)))(transform(clamp(x)));
    }

    scale.invert = function(y) {
      return clamp(untransform((input || (input = piecewise(range, domain.map(transform), interpolateNumber)))(y)));
    };

    scale.domain = function(_) {
      return arguments.length ? (domain = Array.from(_, number$2), rescale()) : domain.slice();
    };

    scale.range = function(_) {
      return arguments.length ? (range = Array.from(_), rescale()) : range.slice();
    };

    scale.rangeRound = function(_) {
      return range = Array.from(_), interpolate = interpolateRound, rescale();
    };

    scale.clamp = function(_) {
      return arguments.length ? (clamp = _ ? true : identity$3, rescale()) : clamp !== identity$3;
    };

    scale.interpolate = function(_) {
      return arguments.length ? (interpolate = _, rescale()) : interpolate;
    };

    scale.unknown = function(_) {
      return arguments.length ? (unknown = _, scale) : unknown;
    };

    return function(t, u) {
      transform = t, untransform = u;
      return rescale();
    };
  }

  function continuous() {
    return transformer$1()(identity$3, identity$3);
  }

  function formatDecimal(x) {
    return Math.abs(x = Math.round(x)) >= 1e21
        ? x.toLocaleString("en").replace(/,/g, "")
        : x.toString(10);
  }

  // Computes the decimal coefficient and exponent of the specified number x with
  // significant digits p, where x is positive and p is in [1, 21] or undefined.
  // For example, formatDecimalParts(1.23) returns ["123", 0].
  function formatDecimalParts(x, p) {
    if (!isFinite(x) || x === 0) return null; // NaN, ±Infinity, ±0
    var i = (x = p ? x.toExponential(p - 1) : x.toExponential()).indexOf("e"), coefficient = x.slice(0, i);

    // The string returned by toExponential either has the form \d\.\d+e[-+]\d+
    // (e.g., 1.2e+3) or the form \de[-+]\d+ (e.g., 1e+3).
    return [
      coefficient.length > 1 ? coefficient[0] + coefficient.slice(2) : coefficient,
      +x.slice(i + 1)
    ];
  }

  function exponent(x) {
    return x = formatDecimalParts(Math.abs(x)), x ? x[1] : NaN;
  }

  function formatGroup(grouping, thousands) {
    return function(value, width) {
      var i = value.length,
          t = [],
          j = 0,
          g = grouping[0],
          length = 0;

      while (i > 0 && g > 0) {
        if (length + g + 1 > width) g = Math.max(1, width - length);
        t.push(value.substring(i -= g, i + g));
        if ((length += g + 1) > width) break;
        g = grouping[j = (j + 1) % grouping.length];
      }

      return t.reverse().join(thousands);
    };
  }

  function formatNumerals(numerals) {
    return function(value) {
      return value.replace(/[0-9]/g, function(i) {
        return numerals[+i];
      });
    };
  }

  // [[fill]align][sign][symbol][0][width][,][.precision][~][type]
  var re = /^(?:(.)?([<>=^]))?([+\-( ])?([$#])?(0)?(\d+)?(,)?(\.\d+)?(~)?([a-z%])?$/i;

  function formatSpecifier(specifier) {
    if (!(match = re.exec(specifier))) throw new Error("invalid format: " + specifier);
    var match;
    return new FormatSpecifier({
      fill: match[1],
      align: match[2],
      sign: match[3],
      symbol: match[4],
      zero: match[5],
      width: match[6],
      comma: match[7],
      precision: match[8] && match[8].slice(1),
      trim: match[9],
      type: match[10]
    });
  }

  formatSpecifier.prototype = FormatSpecifier.prototype; // instanceof

  function FormatSpecifier(specifier) {
    this.fill = specifier.fill === undefined ? " " : specifier.fill + "";
    this.align = specifier.align === undefined ? ">" : specifier.align + "";
    this.sign = specifier.sign === undefined ? "-" : specifier.sign + "";
    this.symbol = specifier.symbol === undefined ? "" : specifier.symbol + "";
    this.zero = !!specifier.zero;
    this.width = specifier.width === undefined ? undefined : +specifier.width;
    this.comma = !!specifier.comma;
    this.precision = specifier.precision === undefined ? undefined : +specifier.precision;
    this.trim = !!specifier.trim;
    this.type = specifier.type === undefined ? "" : specifier.type + "";
  }

  FormatSpecifier.prototype.toString = function() {
    return this.fill
        + this.align
        + this.sign
        + this.symbol
        + (this.zero ? "0" : "")
        + (this.width === undefined ? "" : Math.max(1, this.width | 0))
        + (this.comma ? "," : "")
        + (this.precision === undefined ? "" : "." + Math.max(0, this.precision | 0))
        + (this.trim ? "~" : "")
        + this.type;
  };

  // Trims insignificant zeros, e.g., replaces 1.2000k with 1.2k.
  function formatTrim(s) {
    out: for (var n = s.length, i = 1, i0 = -1, i1; i < n; ++i) {
      switch (s[i]) {
        case ".": i0 = i1 = i; break;
        case "0": if (i0 === 0) i0 = i; i1 = i; break;
        default: if (!+s[i]) break out; if (i0 > 0) i0 = 0; break;
      }
    }
    return i0 > 0 ? s.slice(0, i0) + s.slice(i1 + 1) : s;
  }

  var prefixExponent;

  function formatPrefixAuto(x, p) {
    var d = formatDecimalParts(x, p);
    if (!d) return prefixExponent = undefined, x.toPrecision(p);
    var coefficient = d[0],
        exponent = d[1],
        i = exponent - (prefixExponent = Math.max(-8, Math.min(8, Math.floor(exponent / 3))) * 3) + 1,
        n = coefficient.length;
    return i === n ? coefficient
        : i > n ? coefficient + new Array(i - n + 1).join("0")
        : i > 0 ? coefficient.slice(0, i) + "." + coefficient.slice(i)
        : "0." + new Array(1 - i).join("0") + formatDecimalParts(x, Math.max(0, p + i - 1))[0]; // less than 1y!
  }

  function formatRounded(x, p) {
    var d = formatDecimalParts(x, p);
    if (!d) return x + "";
    var coefficient = d[0],
        exponent = d[1];
    return exponent < 0 ? "0." + new Array(-exponent).join("0") + coefficient
        : coefficient.length > exponent + 1 ? coefficient.slice(0, exponent + 1) + "." + coefficient.slice(exponent + 1)
        : coefficient + new Array(exponent - coefficient.length + 2).join("0");
  }

  var formatTypes = {
    "%": (x, p) => (x * 100).toFixed(p),
    "b": (x) => Math.round(x).toString(2),
    "c": (x) => x + "",
    "d": formatDecimal,
    "e": (x, p) => x.toExponential(p),
    "f": (x, p) => x.toFixed(p),
    "g": (x, p) => x.toPrecision(p),
    "o": (x) => Math.round(x).toString(8),
    "p": (x, p) => formatRounded(x * 100, p),
    "r": formatRounded,
    "s": formatPrefixAuto,
    "X": (x) => Math.round(x).toString(16).toUpperCase(),
    "x": (x) => Math.round(x).toString(16)
  };

  function identity$2(x) {
    return x;
  }

  var map = Array.prototype.map,
      prefixes = ["y","z","a","f","p","n","µ","m","","k","M","G","T","P","E","Z","Y"];

  function formatLocale(locale) {
    var group = locale.grouping === undefined || locale.thousands === undefined ? identity$2 : formatGroup(map.call(locale.grouping, Number), locale.thousands + ""),
        currencyPrefix = locale.currency === undefined ? "" : locale.currency[0] + "",
        currencySuffix = locale.currency === undefined ? "" : locale.currency[1] + "",
        decimal = locale.decimal === undefined ? "." : locale.decimal + "",
        numerals = locale.numerals === undefined ? identity$2 : formatNumerals(map.call(locale.numerals, String)),
        percent = locale.percent === undefined ? "%" : locale.percent + "",
        minus = locale.minus === undefined ? "−" : locale.minus + "",
        nan = locale.nan === undefined ? "NaN" : locale.nan + "";

    function newFormat(specifier, options) {
      specifier = formatSpecifier(specifier);

      var fill = specifier.fill,
          align = specifier.align,
          sign = specifier.sign,
          symbol = specifier.symbol,
          zero = specifier.zero,
          width = specifier.width,
          comma = specifier.comma,
          precision = specifier.precision,
          trim = specifier.trim,
          type = specifier.type;

      // The "n" type is an alias for ",g".
      if (type === "n") comma = true, type = "g";

      // The "" type, and any invalid type, is an alias for ".12~g".
      else if (!formatTypes[type]) precision === undefined && (precision = 12), trim = true, type = "g";

      // If zero fill is specified, padding goes after sign and before digits.
      if (zero || (fill === "0" && align === "=")) zero = true, fill = "0", align = "=";

      // Compute the prefix and suffix.
      // For SI-prefix, the suffix is lazily computed.
      var prefix = (options && options.prefix !== undefined ? options.prefix : "") + (symbol === "$" ? currencyPrefix : symbol === "#" && /[boxX]/.test(type) ? "0" + type.toLowerCase() : ""),
          suffix = (symbol === "$" ? currencySuffix : /[%p]/.test(type) ? percent : "") + (options && options.suffix !== undefined ? options.suffix : "");

      // What format function should we use?
      // Is this an integer type?
      // Can this type generate exponential notation?
      var formatType = formatTypes[type],
          maybeSuffix = /[defgprs%]/.test(type);

      // Set the default precision if not specified,
      // or clamp the specified precision to the supported range.
      // For significant precision, it must be in [1, 21].
      // For fixed precision, it must be in [0, 20].
      precision = precision === undefined ? 6
          : /[gprs]/.test(type) ? Math.max(1, Math.min(21, precision))
          : Math.max(0, Math.min(20, precision));

      function format(value) {
        var valuePrefix = prefix,
            valueSuffix = suffix,
            i, n, c;

        if (type === "c") {
          valueSuffix = formatType(value) + valueSuffix;
          value = "";
        } else {
          value = +value;

          // Determine the sign. -0 is not less than 0, but 1 / -0 is!
          var valueNegative = value < 0 || 1 / value < 0;

          // Perform the initial formatting.
          value = isNaN(value) ? nan : formatType(Math.abs(value), precision);

          // Trim insignificant zeros.
          if (trim) value = formatTrim(value);

          // If a negative value rounds to zero after formatting, and no explicit positive sign is requested, hide the sign.
          if (valueNegative && +value === 0 && sign !== "+") valueNegative = false;

          // Compute the prefix and suffix.
          valuePrefix = (valueNegative ? (sign === "(" ? sign : minus) : sign === "-" || sign === "(" ? "" : sign) + valuePrefix;
          valueSuffix = (type === "s" && !isNaN(value) && prefixExponent !== undefined ? prefixes[8 + prefixExponent / 3] : "") + valueSuffix + (valueNegative && sign === "(" ? ")" : "");

          // Break the formatted value into the integer “value” part that can be
          // grouped, and fractional or exponential “suffix” part that is not.
          if (maybeSuffix) {
            i = -1, n = value.length;
            while (++i < n) {
              if (c = value.charCodeAt(i), 48 > c || c > 57) {
                valueSuffix = (c === 46 ? decimal + value.slice(i + 1) : value.slice(i)) + valueSuffix;
                value = value.slice(0, i);
                break;
              }
            }
          }
        }

        // If the fill character is not "0", grouping is applied before padding.
        if (comma && !zero) value = group(value, Infinity);

        // Compute the padding.
        var length = valuePrefix.length + value.length + valueSuffix.length,
            padding = length < width ? new Array(width - length + 1).join(fill) : "";

        // If the fill character is "0", grouping is applied after padding.
        if (comma && zero) value = group(padding + value, padding.length ? width - valueSuffix.length : Infinity), padding = "";

        // Reconstruct the final output based on the desired alignment.
        switch (align) {
          case "<": value = valuePrefix + value + valueSuffix + padding; break;
          case "=": value = valuePrefix + padding + value + valueSuffix; break;
          case "^": value = padding.slice(0, length = padding.length >> 1) + valuePrefix + value + valueSuffix + padding.slice(length); break;
          default: value = padding + valuePrefix + value + valueSuffix; break;
        }

        return numerals(value);
      }

      format.toString = function() {
        return specifier + "";
      };

      return format;
    }

    function formatPrefix(specifier, value) {
      var e = Math.max(-8, Math.min(8, Math.floor(exponent(value) / 3))) * 3,
          k = Math.pow(10, -e),
          f = newFormat((specifier = formatSpecifier(specifier), specifier.type = "f", specifier), {suffix: prefixes[8 + e / 3]});
      return function(value) {
        return f(k * value);
      };
    }

    return {
      format: newFormat,
      formatPrefix: formatPrefix
    };
  }

  var locale;
  var format;
  var formatPrefix;

  defaultLocale({
    thousands: ",",
    grouping: [3],
    currency: ["$", ""]
  });

  function defaultLocale(definition) {
    locale = formatLocale(definition);
    format = locale.format;
    formatPrefix = locale.formatPrefix;
    return locale;
  }

  function precisionFixed(step) {
    return Math.max(0, -exponent(Math.abs(step)));
  }

  function precisionPrefix(step, value) {
    return Math.max(0, Math.max(-8, Math.min(8, Math.floor(exponent(value) / 3))) * 3 - exponent(Math.abs(step)));
  }

  function precisionRound(step, max) {
    step = Math.abs(step), max = Math.abs(max) - step;
    return Math.max(0, exponent(max) - exponent(step)) + 1;
  }

  function tickFormat(start, stop, count, specifier) {
    var step = tickStep(start, stop, count),
        precision;
    specifier = formatSpecifier(specifier == null ? ",f" : specifier);
    switch (specifier.type) {
      case "s": {
        var value = Math.max(Math.abs(start), Math.abs(stop));
        if (specifier.precision == null && !isNaN(precision = precisionPrefix(step, value))) specifier.precision = precision;
        return formatPrefix(specifier, value);
      }
      case "":
      case "e":
      case "g":
      case "p":
      case "r": {
        if (specifier.precision == null && !isNaN(precision = precisionRound(step, Math.max(Math.abs(start), Math.abs(stop))))) specifier.precision = precision - (specifier.type === "e");
        break;
      }
      case "f":
      case "%": {
        if (specifier.precision == null && !isNaN(precision = precisionFixed(step))) specifier.precision = precision - (specifier.type === "%") * 2;
        break;
      }
    }
    return format(specifier);
  }

  function linearish(scale) {
    var domain = scale.domain;

    scale.ticks = function(count) {
      var d = domain();
      return ticks(d[0], d[d.length - 1], count == null ? 10 : count);
    };

    scale.tickFormat = function(count, specifier) {
      var d = domain();
      return tickFormat(d[0], d[d.length - 1], count == null ? 10 : count, specifier);
    };

    scale.nice = function(count) {
      if (count == null) count = 10;

      var d = domain();
      var i0 = 0;
      var i1 = d.length - 1;
      var start = d[i0];
      var stop = d[i1];
      var prestep;
      var step;
      var maxIter = 10;

      if (stop < start) {
        step = start, start = stop, stop = step;
        step = i0, i0 = i1, i1 = step;
      }
      
      while (maxIter-- > 0) {
        step = tickIncrement(start, stop, count);
        if (step === prestep) {
          d[i0] = start;
          d[i1] = stop;
          return domain(d);
        } else if (step > 0) {
          start = Math.floor(start / step) * step;
          stop = Math.ceil(stop / step) * step;
        } else if (step < 0) {
          start = Math.ceil(start * step) / step;
          stop = Math.floor(stop * step) / step;
        } else {
          break;
        }
        prestep = step;
      }

      return scale;
    };

    return scale;
  }

  function linear$1() {
    var scale = continuous();

    scale.copy = function() {
      return copy$1(scale, linear$1());
    };

    initRange.apply(scale, arguments);

    return linearish(scale);
  }

  function transformer() {
    var x0 = 0,
        x1 = 1,
        t0,
        t1,
        k10,
        transform,
        interpolator = identity$3,
        clamp = false,
        unknown;

    function scale(x) {
      return x == null || isNaN(x = +x) ? unknown : interpolator(k10 === 0 ? 0.5 : (x = (transform(x) - t0) * k10, clamp ? Math.max(0, Math.min(1, x)) : x));
    }

    scale.domain = function(_) {
      return arguments.length ? ([x0, x1] = _, t0 = transform(x0 = +x0), t1 = transform(x1 = +x1), k10 = t0 === t1 ? 0 : 1 / (t1 - t0), scale) : [x0, x1];
    };

    scale.clamp = function(_) {
      return arguments.length ? (clamp = !!_, scale) : clamp;
    };

    scale.interpolator = function(_) {
      return arguments.length ? (interpolator = _, scale) : interpolator;
    };

    function range(interpolate) {
      return function(_) {
        var r0, r1;
        return arguments.length ? ([r0, r1] = _, interpolator = interpolate(r0, r1), scale) : [interpolator(0), interpolator(1)];
      };
    }

    scale.range = range(interpolate$2);

    scale.rangeRound = range(interpolateRound);

    scale.unknown = function(_) {
      return arguments.length ? (unknown = _, scale) : unknown;
    };

    return function(t) {
      transform = t, t0 = t(x0), t1 = t(x1), k10 = t0 === t1 ? 0 : 1 / (t1 - t0);
      return scale;
    };
  }

  function copy(source, target) {
    return target
        .domain(source.domain())
        .interpolator(source.interpolator())
        .clamp(source.clamp())
        .unknown(source.unknown());
  }

  function sequential() {
    var scale = linearish(transformer()(identity$3));

    scale.copy = function() {
      return copy(scale, sequential());
    };

    return initInterpolator.apply(scale, arguments);
  }

  var xhtml = "http://www.w3.org/1999/xhtml";

  var namespaces = {
    svg: "http://www.w3.org/2000/svg",
    xhtml: xhtml,
    xlink: "http://www.w3.org/1999/xlink",
    xml: "http://www.w3.org/XML/1998/namespace",
    xmlns: "http://www.w3.org/2000/xmlns/"
  };

  function namespace(name) {
    var prefix = name += "", i = prefix.indexOf(":");
    if (i >= 0 && (prefix = name.slice(0, i)) !== "xmlns") name = name.slice(i + 1);
    return namespaces.hasOwnProperty(prefix) ? {space: namespaces[prefix], local: name} : name; // eslint-disable-line no-prototype-builtins
  }

  function creatorInherit(name) {
    return function() {
      var document = this.ownerDocument,
          uri = this.namespaceURI;
      return uri === xhtml && document.documentElement.namespaceURI === xhtml
          ? document.createElement(name)
          : document.createElementNS(uri, name);
    };
  }

  function creatorFixed(fullname) {
    return function() {
      return this.ownerDocument.createElementNS(fullname.space, fullname.local);
    };
  }

  function creator(name) {
    var fullname = namespace(name);
    return (fullname.local
        ? creatorFixed
        : creatorInherit)(fullname);
  }

  function none$2() {}

  function selector(selector) {
    return selector == null ? none$2 : function() {
      return this.querySelector(selector);
    };
  }

  function selection_select(select) {
    if (typeof select !== "function") select = selector(select);

    for (var groups = this._groups, m = groups.length, subgroups = new Array(m), j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, subgroup = subgroups[j] = new Array(n), node, subnode, i = 0; i < n; ++i) {
        if ((node = group[i]) && (subnode = select.call(node, node.__data__, i, group))) {
          if ("__data__" in node) subnode.__data__ = node.__data__;
          subgroup[i] = subnode;
        }
      }
    }

    return new Selection$1(subgroups, this._parents);
  }

  // Given something array like (or null), returns something that is strictly an
  // array. This is used to ensure that array-like objects passed to d3.selectAll
  // or selection.selectAll are converted into proper arrays when creating a
  // selection; we don’t ever want to create a selection backed by a live
  // HTMLCollection or NodeList. However, note that selection.selectAll will use a
  // static NodeList as a group, since it safely derived from querySelectorAll.
  function array$1(x) {
    return x == null ? [] : Array.isArray(x) ? x : Array.from(x);
  }

  function empty$1() {
    return [];
  }

  function selectorAll(selector) {
    return selector == null ? empty$1 : function() {
      return this.querySelectorAll(selector);
    };
  }

  function arrayAll(select) {
    return function() {
      return array$1(select.apply(this, arguments));
    };
  }

  function selection_selectAll(select) {
    if (typeof select === "function") select = arrayAll(select);
    else select = selectorAll(select);

    for (var groups = this._groups, m = groups.length, subgroups = [], parents = [], j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, node, i = 0; i < n; ++i) {
        if (node = group[i]) {
          subgroups.push(select.call(node, node.__data__, i, group));
          parents.push(node);
        }
      }
    }

    return new Selection$1(subgroups, parents);
  }

  function matcher(selector) {
    return function() {
      return this.matches(selector);
    };
  }

  function childMatcher(selector) {
    return function(node) {
      return node.matches(selector);
    };
  }

  var find$1 = Array.prototype.find;

  function childFind(match) {
    return function() {
      return find$1.call(this.children, match);
    };
  }

  function childFirst() {
    return this.firstElementChild;
  }

  function selection_selectChild(match) {
    return this.select(match == null ? childFirst
        : childFind(typeof match === "function" ? match : childMatcher(match)));
  }

  var filter = Array.prototype.filter;

  function children() {
    return Array.from(this.children);
  }

  function childrenFilter(match) {
    return function() {
      return filter.call(this.children, match);
    };
  }

  function selection_selectChildren(match) {
    return this.selectAll(match == null ? children
        : childrenFilter(typeof match === "function" ? match : childMatcher(match)));
  }

  function selection_filter(match) {
    if (typeof match !== "function") match = matcher(match);

    for (var groups = this._groups, m = groups.length, subgroups = new Array(m), j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, subgroup = subgroups[j] = [], node, i = 0; i < n; ++i) {
        if ((node = group[i]) && match.call(node, node.__data__, i, group)) {
          subgroup.push(node);
        }
      }
    }

    return new Selection$1(subgroups, this._parents);
  }

  function sparse(update) {
    return new Array(update.length);
  }

  function selection_enter() {
    return new Selection$1(this._enter || this._groups.map(sparse), this._parents);
  }

  function EnterNode(parent, datum) {
    this.ownerDocument = parent.ownerDocument;
    this.namespaceURI = parent.namespaceURI;
    this._next = null;
    this._parent = parent;
    this.__data__ = datum;
  }

  EnterNode.prototype = {
    constructor: EnterNode,
    appendChild: function(child) { return this._parent.insertBefore(child, this._next); },
    insertBefore: function(child, next) { return this._parent.insertBefore(child, next); },
    querySelector: function(selector) { return this._parent.querySelector(selector); },
    querySelectorAll: function(selector) { return this._parent.querySelectorAll(selector); }
  };

  function constant$5(x) {
    return function() {
      return x;
    };
  }

  function bindIndex(parent, group, enter, update, exit, data) {
    var i = 0,
        node,
        groupLength = group.length,
        dataLength = data.length;

    // Put any non-null nodes that fit into update.
    // Put any null nodes into enter.
    // Put any remaining data into enter.
    for (; i < dataLength; ++i) {
      if (node = group[i]) {
        node.__data__ = data[i];
        update[i] = node;
      } else {
        enter[i] = new EnterNode(parent, data[i]);
      }
    }

    // Put any non-null nodes that don’t fit into exit.
    for (; i < groupLength; ++i) {
      if (node = group[i]) {
        exit[i] = node;
      }
    }
  }

  function bindKey(parent, group, enter, update, exit, data, key) {
    var i,
        node,
        nodeByKeyValue = new Map,
        groupLength = group.length,
        dataLength = data.length,
        keyValues = new Array(groupLength),
        keyValue;

    // Compute the key for each node.
    // If multiple nodes have the same key, the duplicates are added to exit.
    for (i = 0; i < groupLength; ++i) {
      if (node = group[i]) {
        keyValues[i] = keyValue = key.call(node, node.__data__, i, group) + "";
        if (nodeByKeyValue.has(keyValue)) {
          exit[i] = node;
        } else {
          nodeByKeyValue.set(keyValue, node);
        }
      }
    }

    // Compute the key for each datum.
    // If there a node associated with this key, join and add it to update.
    // If there is not (or the key is a duplicate), add it to enter.
    for (i = 0; i < dataLength; ++i) {
      keyValue = key.call(parent, data[i], i, data) + "";
      if (node = nodeByKeyValue.get(keyValue)) {
        update[i] = node;
        node.__data__ = data[i];
        nodeByKeyValue.delete(keyValue);
      } else {
        enter[i] = new EnterNode(parent, data[i]);
      }
    }

    // Add any remaining nodes that were not bound to data to exit.
    for (i = 0; i < groupLength; ++i) {
      if ((node = group[i]) && (nodeByKeyValue.get(keyValues[i]) === node)) {
        exit[i] = node;
      }
    }
  }

  function datum(node) {
    return node.__data__;
  }

  function selection_data(value, key) {
    if (!arguments.length) return Array.from(this, datum);

    var bind = key ? bindKey : bindIndex,
        parents = this._parents,
        groups = this._groups;

    if (typeof value !== "function") value = constant$5(value);

    for (var m = groups.length, update = new Array(m), enter = new Array(m), exit = new Array(m), j = 0; j < m; ++j) {
      var parent = parents[j],
          group = groups[j],
          groupLength = group.length,
          data = arraylike(value.call(parent, parent && parent.__data__, j, parents)),
          dataLength = data.length,
          enterGroup = enter[j] = new Array(dataLength),
          updateGroup = update[j] = new Array(dataLength),
          exitGroup = exit[j] = new Array(groupLength);

      bind(parent, group, enterGroup, updateGroup, exitGroup, data, key);

      // Now connect the enter nodes to their following update node, such that
      // appendChild can insert the materialized enter node before this node,
      // rather than at the end of the parent node.
      for (var i0 = 0, i1 = 0, previous, next; i0 < dataLength; ++i0) {
        if (previous = enterGroup[i0]) {
          if (i0 >= i1) i1 = i0 + 1;
          while (!(next = updateGroup[i1]) && ++i1 < dataLength);
          previous._next = next || null;
        }
      }
    }

    update = new Selection$1(update, parents);
    update._enter = enter;
    update._exit = exit;
    return update;
  }

  // Given some data, this returns an array-like view of it: an object that
  // exposes a length property and allows numeric indexing. Note that unlike
  // selectAll, this isn’t worried about “live” collections because the resulting
  // array will only be used briefly while data is being bound. (It is possible to
  // cause the data to change while iterating by using a key function, but please
  // don’t; we’d rather avoid a gratuitous copy.)
  function arraylike(data) {
    return typeof data === "object" && "length" in data
      ? data // Array, TypedArray, NodeList, array-like
      : Array.from(data); // Map, Set, iterable, string, or anything else
  }

  function selection_exit() {
    return new Selection$1(this._exit || this._groups.map(sparse), this._parents);
  }

  function selection_join(onenter, onupdate, onexit) {
    var enter = this.enter(), update = this, exit = this.exit();
    if (typeof onenter === "function") {
      enter = onenter(enter);
      if (enter) enter = enter.selection();
    } else {
      enter = enter.append(onenter + "");
    }
    if (onupdate != null) {
      update = onupdate(update);
      if (update) update = update.selection();
    }
    if (onexit == null) exit.remove(); else onexit(exit);
    return enter && update ? enter.merge(update).order() : update;
  }

  function selection_merge(context) {
    var selection = context.selection ? context.selection() : context;

    for (var groups0 = this._groups, groups1 = selection._groups, m0 = groups0.length, m1 = groups1.length, m = Math.min(m0, m1), merges = new Array(m0), j = 0; j < m; ++j) {
      for (var group0 = groups0[j], group1 = groups1[j], n = group0.length, merge = merges[j] = new Array(n), node, i = 0; i < n; ++i) {
        if (node = group0[i] || group1[i]) {
          merge[i] = node;
        }
      }
    }

    for (; j < m0; ++j) {
      merges[j] = groups0[j];
    }

    return new Selection$1(merges, this._parents);
  }

  function selection_order() {

    for (var groups = this._groups, j = -1, m = groups.length; ++j < m;) {
      for (var group = groups[j], i = group.length - 1, next = group[i], node; --i >= 0;) {
        if (node = group[i]) {
          if (next && node.compareDocumentPosition(next) ^ 4) next.parentNode.insertBefore(node, next);
          next = node;
        }
      }
    }

    return this;
  }

  function selection_sort(compare) {
    if (!compare) compare = ascending;

    function compareNode(a, b) {
      return a && b ? compare(a.__data__, b.__data__) : !a - !b;
    }

    for (var groups = this._groups, m = groups.length, sortgroups = new Array(m), j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, sortgroup = sortgroups[j] = new Array(n), node, i = 0; i < n; ++i) {
        if (node = group[i]) {
          sortgroup[i] = node;
        }
      }
      sortgroup.sort(compareNode);
    }

    return new Selection$1(sortgroups, this._parents).order();
  }

  function ascending(a, b) {
    return a < b ? -1 : a > b ? 1 : a >= b ? 0 : NaN;
  }

  function selection_call() {
    var callback = arguments[0];
    arguments[0] = this;
    callback.apply(null, arguments);
    return this;
  }

  function selection_nodes() {
    return Array.from(this);
  }

  function selection_node() {

    for (var groups = this._groups, j = 0, m = groups.length; j < m; ++j) {
      for (var group = groups[j], i = 0, n = group.length; i < n; ++i) {
        var node = group[i];
        if (node) return node;
      }
    }

    return null;
  }

  function selection_size() {
    let size = 0;
    for (const node of this) ++size; // eslint-disable-line no-unused-vars
    return size;
  }

  function selection_empty() {
    return !this.node();
  }

  function selection_each(callback) {

    for (var groups = this._groups, j = 0, m = groups.length; j < m; ++j) {
      for (var group = groups[j], i = 0, n = group.length, node; i < n; ++i) {
        if (node = group[i]) callback.call(node, node.__data__, i, group);
      }
    }

    return this;
  }

  function attrRemove$1(name) {
    return function() {
      this.removeAttribute(name);
    };
  }

  function attrRemoveNS$1(fullname) {
    return function() {
      this.removeAttributeNS(fullname.space, fullname.local);
    };
  }

  function attrConstant$1(name, value) {
    return function() {
      this.setAttribute(name, value);
    };
  }

  function attrConstantNS$1(fullname, value) {
    return function() {
      this.setAttributeNS(fullname.space, fullname.local, value);
    };
  }

  function attrFunction$1(name, value) {
    return function() {
      var v = value.apply(this, arguments);
      if (v == null) this.removeAttribute(name);
      else this.setAttribute(name, v);
    };
  }

  function attrFunctionNS$1(fullname, value) {
    return function() {
      var v = value.apply(this, arguments);
      if (v == null) this.removeAttributeNS(fullname.space, fullname.local);
      else this.setAttributeNS(fullname.space, fullname.local, v);
    };
  }

  function selection_attr(name, value) {
    var fullname = namespace(name);

    if (arguments.length < 2) {
      var node = this.node();
      return fullname.local
          ? node.getAttributeNS(fullname.space, fullname.local)
          : node.getAttribute(fullname);
    }

    return this.each((value == null
        ? (fullname.local ? attrRemoveNS$1 : attrRemove$1) : (typeof value === "function"
        ? (fullname.local ? attrFunctionNS$1 : attrFunction$1)
        : (fullname.local ? attrConstantNS$1 : attrConstant$1)))(fullname, value));
  }

  function defaultView(node) {
    return (node.ownerDocument && node.ownerDocument.defaultView) // node is a Node
        || (node.document && node) // node is a Window
        || node.defaultView; // node is a Document
  }

  function styleRemove$1(name) {
    return function() {
      this.style.removeProperty(name);
    };
  }

  function styleConstant$1(name, value, priority) {
    return function() {
      this.style.setProperty(name, value, priority);
    };
  }

  function styleFunction$1(name, value, priority) {
    return function() {
      var v = value.apply(this, arguments);
      if (v == null) this.style.removeProperty(name);
      else this.style.setProperty(name, v, priority);
    };
  }

  function selection_style(name, value, priority) {
    return arguments.length > 1
        ? this.each((value == null
              ? styleRemove$1 : typeof value === "function"
              ? styleFunction$1
              : styleConstant$1)(name, value, priority == null ? "" : priority))
        : styleValue(this.node(), name);
  }

  function styleValue(node, name) {
    return node.style.getPropertyValue(name)
        || defaultView(node).getComputedStyle(node, null).getPropertyValue(name);
  }

  function propertyRemove(name) {
    return function() {
      delete this[name];
    };
  }

  function propertyConstant(name, value) {
    return function() {
      this[name] = value;
    };
  }

  function propertyFunction(name, value) {
    return function() {
      var v = value.apply(this, arguments);
      if (v == null) delete this[name];
      else this[name] = v;
    };
  }

  function selection_property(name, value) {
    return arguments.length > 1
        ? this.each((value == null
            ? propertyRemove : typeof value === "function"
            ? propertyFunction
            : propertyConstant)(name, value))
        : this.node()[name];
  }

  function classArray(string) {
    return string.trim().split(/^|\s+/);
  }

  function classList(node) {
    return node.classList || new ClassList(node);
  }

  function ClassList(node) {
    this._node = node;
    this._names = classArray(node.getAttribute("class") || "");
  }

  ClassList.prototype = {
    add: function(name) {
      var i = this._names.indexOf(name);
      if (i < 0) {
        this._names.push(name);
        this._node.setAttribute("class", this._names.join(" "));
      }
    },
    remove: function(name) {
      var i = this._names.indexOf(name);
      if (i >= 0) {
        this._names.splice(i, 1);
        this._node.setAttribute("class", this._names.join(" "));
      }
    },
    contains: function(name) {
      return this._names.indexOf(name) >= 0;
    }
  };

  function classedAdd(node, names) {
    var list = classList(node), i = -1, n = names.length;
    while (++i < n) list.add(names[i]);
  }

  function classedRemove(node, names) {
    var list = classList(node), i = -1, n = names.length;
    while (++i < n) list.remove(names[i]);
  }

  function classedTrue(names) {
    return function() {
      classedAdd(this, names);
    };
  }

  function classedFalse(names) {
    return function() {
      classedRemove(this, names);
    };
  }

  function classedFunction(names, value) {
    return function() {
      (value.apply(this, arguments) ? classedAdd : classedRemove)(this, names);
    };
  }

  function selection_classed(name, value) {
    var names = classArray(name + "");

    if (arguments.length < 2) {
      var list = classList(this.node()), i = -1, n = names.length;
      while (++i < n) if (!list.contains(names[i])) return false;
      return true;
    }

    return this.each((typeof value === "function"
        ? classedFunction : value
        ? classedTrue
        : classedFalse)(names, value));
  }

  function textRemove() {
    this.textContent = "";
  }

  function textConstant$1(value) {
    return function() {
      this.textContent = value;
    };
  }

  function textFunction$1(value) {
    return function() {
      var v = value.apply(this, arguments);
      this.textContent = v == null ? "" : v;
    };
  }

  function selection_text(value) {
    return arguments.length
        ? this.each(value == null
            ? textRemove : (typeof value === "function"
            ? textFunction$1
            : textConstant$1)(value))
        : this.node().textContent;
  }

  function htmlRemove() {
    this.innerHTML = "";
  }

  function htmlConstant(value) {
    return function() {
      this.innerHTML = value;
    };
  }

  function htmlFunction(value) {
    return function() {
      var v = value.apply(this, arguments);
      this.innerHTML = v == null ? "" : v;
    };
  }

  function selection_html(value) {
    return arguments.length
        ? this.each(value == null
            ? htmlRemove : (typeof value === "function"
            ? htmlFunction
            : htmlConstant)(value))
        : this.node().innerHTML;
  }

  function raise() {
    if (this.nextSibling) this.parentNode.appendChild(this);
  }

  function selection_raise() {
    return this.each(raise);
  }

  function lower() {
    if (this.previousSibling) this.parentNode.insertBefore(this, this.parentNode.firstChild);
  }

  function selection_lower() {
    return this.each(lower);
  }

  function selection_append(name) {
    var create = typeof name === "function" ? name : creator(name);
    return this.select(function() {
      return this.appendChild(create.apply(this, arguments));
    });
  }

  function constantNull() {
    return null;
  }

  function selection_insert(name, before) {
    var create = typeof name === "function" ? name : creator(name),
        select = before == null ? constantNull : typeof before === "function" ? before : selector(before);
    return this.select(function() {
      return this.insertBefore(create.apply(this, arguments), select.apply(this, arguments) || null);
    });
  }

  function remove() {
    var parent = this.parentNode;
    if (parent) parent.removeChild(this);
  }

  function selection_remove() {
    return this.each(remove);
  }

  function selection_cloneShallow() {
    var clone = this.cloneNode(false), parent = this.parentNode;
    return parent ? parent.insertBefore(clone, this.nextSibling) : clone;
  }

  function selection_cloneDeep() {
    var clone = this.cloneNode(true), parent = this.parentNode;
    return parent ? parent.insertBefore(clone, this.nextSibling) : clone;
  }

  function selection_clone(deep) {
    return this.select(deep ? selection_cloneDeep : selection_cloneShallow);
  }

  function selection_datum(value) {
    return arguments.length
        ? this.property("__data__", value)
        : this.node().__data__;
  }

  function contextListener(listener) {
    return function(event) {
      listener.call(this, event, this.__data__);
    };
  }

  function parseTypenames$2(typenames) {
    return typenames.trim().split(/^|\s+/).map(function(t) {
      var name = "", i = t.indexOf(".");
      if (i >= 0) name = t.slice(i + 1), t = t.slice(0, i);
      return {type: t, name: name};
    });
  }

  function onRemove(typename) {
    return function() {
      var on = this.__on;
      if (!on) return;
      for (var j = 0, i = -1, m = on.length, o; j < m; ++j) {
        if (o = on[j], (!typename.type || o.type === typename.type) && o.name === typename.name) {
          this.removeEventListener(o.type, o.listener, o.options);
        } else {
          on[++i] = o;
        }
      }
      if (++i) on.length = i;
      else delete this.__on;
    };
  }

  function onAdd(typename, value, options) {
    return function() {
      var on = this.__on, o, listener = contextListener(value);
      if (on) for (var j = 0, m = on.length; j < m; ++j) {
        if ((o = on[j]).type === typename.type && o.name === typename.name) {
          this.removeEventListener(o.type, o.listener, o.options);
          this.addEventListener(o.type, o.listener = listener, o.options = options);
          o.value = value;
          return;
        }
      }
      this.addEventListener(typename.type, listener, options);
      o = {type: typename.type, name: typename.name, value: value, listener: listener, options: options};
      if (!on) this.__on = [o];
      else on.push(o);
    };
  }

  function selection_on(typename, value, options) {
    var typenames = parseTypenames$2(typename + ""), i, n = typenames.length, t;

    if (arguments.length < 2) {
      var on = this.node().__on;
      if (on) for (var j = 0, m = on.length, o; j < m; ++j) {
        for (i = 0, o = on[j]; i < n; ++i) {
          if ((t = typenames[i]).type === o.type && t.name === o.name) {
            return o.value;
          }
        }
      }
      return;
    }

    on = value ? onAdd : onRemove;
    for (i = 0; i < n; ++i) this.each(on(typenames[i], value, options));
    return this;
  }

  function dispatchEvent(node, type, params) {
    var window = defaultView(node),
        event = window.CustomEvent;

    if (typeof event === "function") {
      event = new event(type, params);
    } else {
      event = window.document.createEvent("Event");
      if (params) event.initEvent(type, params.bubbles, params.cancelable), event.detail = params.detail;
      else event.initEvent(type, false, false);
    }

    node.dispatchEvent(event);
  }

  function dispatchConstant(type, params) {
    return function() {
      return dispatchEvent(this, type, params);
    };
  }

  function dispatchFunction(type, params) {
    return function() {
      return dispatchEvent(this, type, params.apply(this, arguments));
    };
  }

  function selection_dispatch(type, params) {
    return this.each((typeof params === "function"
        ? dispatchFunction
        : dispatchConstant)(type, params));
  }

  function* selection_iterator() {
    for (var groups = this._groups, j = 0, m = groups.length; j < m; ++j) {
      for (var group = groups[j], i = 0, n = group.length, node; i < n; ++i) {
        if (node = group[i]) yield node;
      }
    }
  }

  var root = [null];

  function Selection$1(groups, parents) {
    this._groups = groups;
    this._parents = parents;
  }

  function selection() {
    return new Selection$1([[document.documentElement]], root);
  }

  function selection_selection() {
    return this;
  }

  Selection$1.prototype = selection.prototype = {
    constructor: Selection$1,
    select: selection_select,
    selectAll: selection_selectAll,
    selectChild: selection_selectChild,
    selectChildren: selection_selectChildren,
    filter: selection_filter,
    data: selection_data,
    enter: selection_enter,
    exit: selection_exit,
    join: selection_join,
    merge: selection_merge,
    selection: selection_selection,
    order: selection_order,
    sort: selection_sort,
    call: selection_call,
    nodes: selection_nodes,
    node: selection_node,
    size: selection_size,
    empty: selection_empty,
    each: selection_each,
    attr: selection_attr,
    style: selection_style,
    property: selection_property,
    classed: selection_classed,
    text: selection_text,
    html: selection_html,
    raise: selection_raise,
    lower: selection_lower,
    append: selection_append,
    insert: selection_insert,
    remove: selection_remove,
    clone: selection_clone,
    datum: selection_datum,
    on: selection_on,
    dispatch: selection_dispatch,
    [Symbol.iterator]: selection_iterator
  };

  function select(selector) {
    return typeof selector === "string"
        ? new Selection$1([[document.querySelector(selector)]], [document.documentElement])
        : new Selection$1([[selector]], root);
  }

  function sourceEvent(event) {
    let sourceEvent;
    while (sourceEvent = event.sourceEvent) event = sourceEvent;
    return event;
  }

  function pointer(event, node) {
    event = sourceEvent(event);
    if (node === undefined) node = event.currentTarget;
    if (node) {
      var svg = node.ownerSVGElement || node;
      if (svg.createSVGPoint) {
        var point = svg.createSVGPoint();
        point.x = event.clientX, point.y = event.clientY;
        point = point.matrixTransform(node.getScreenCTM().inverse());
        return [point.x, point.y];
      }
      if (node.getBoundingClientRect) {
        var rect = node.getBoundingClientRect();
        return [event.clientX - rect.left - node.clientLeft, event.clientY - rect.top - node.clientTop];
      }
    }
    return [event.pageX, event.pageY];
  }

  function constant$4(x) {
    return function constant() {
      return x;
    };
  }

  const abs$2 = Math.abs;
  const atan2 = Math.atan2;
  const cos$1 = Math.cos;
  const max$2 = Math.max;
  const min$1 = Math.min;
  const sin$1 = Math.sin;
  const sqrt = Math.sqrt;

  const epsilon$4 = 1e-12;
  const pi$3 = Math.PI;
  const halfPi$1 = pi$3 / 2;
  const tau$3 = 2 * pi$3;

  function acos(x) {
    return x > 1 ? 0 : x < -1 ? pi$3 : Math.acos(x);
  }

  function asin(x) {
    return x >= 1 ? halfPi$1 : x <= -1 ? -halfPi$1 : Math.asin(x);
  }

  const pi$2 = Math.PI,
      tau$2 = 2 * pi$2,
      epsilon$3 = 1e-6,
      tauEpsilon$1 = tau$2 - epsilon$3;

  function append$1(strings) {
    this._ += strings[0];
    for (let i = 1, n = strings.length; i < n; ++i) {
      this._ += arguments[i] + strings[i];
    }
  }

  function appendRound$1(digits) {
    let d = Math.floor(digits);
    if (!(d >= 0)) throw new Error(`invalid digits: ${digits}`);
    if (d > 15) return append$1;
    const k = 10 ** d;
    return function(strings) {
      this._ += strings[0];
      for (let i = 1, n = strings.length; i < n; ++i) {
        this._ += Math.round(arguments[i] * k) / k + strings[i];
      }
    };
  }

  let Path$1 = class Path {
    constructor(digits) {
      this._x0 = this._y0 = // start of current subpath
      this._x1 = this._y1 = null; // end of current subpath
      this._ = "";
      this._append = digits == null ? append$1 : appendRound$1(digits);
    }
    moveTo(x, y) {
      this._append`M${this._x0 = this._x1 = +x},${this._y0 = this._y1 = +y}`;
    }
    closePath() {
      if (this._x1 !== null) {
        this._x1 = this._x0, this._y1 = this._y0;
        this._append`Z`;
      }
    }
    lineTo(x, y) {
      this._append`L${this._x1 = +x},${this._y1 = +y}`;
    }
    quadraticCurveTo(x1, y1, x, y) {
      this._append`Q${+x1},${+y1},${this._x1 = +x},${this._y1 = +y}`;
    }
    bezierCurveTo(x1, y1, x2, y2, x, y) {
      this._append`C${+x1},${+y1},${+x2},${+y2},${this._x1 = +x},${this._y1 = +y}`;
    }
    arcTo(x1, y1, x2, y2, r) {
      x1 = +x1, y1 = +y1, x2 = +x2, y2 = +y2, r = +r;

      // Is the radius negative? Error.
      if (r < 0) throw new Error(`negative radius: ${r}`);

      let x0 = this._x1,
          y0 = this._y1,
          x21 = x2 - x1,
          y21 = y2 - y1,
          x01 = x0 - x1,
          y01 = y0 - y1,
          l01_2 = x01 * x01 + y01 * y01;

      // Is this path empty? Move to (x1,y1).
      if (this._x1 === null) {
        this._append`M${this._x1 = x1},${this._y1 = y1}`;
      }

      // Or, is (x1,y1) coincident with (x0,y0)? Do nothing.
      else if (!(l01_2 > epsilon$3));

      // Or, are (x0,y0), (x1,y1) and (x2,y2) collinear?
      // Equivalently, is (x1,y1) coincident with (x2,y2)?
      // Or, is the radius zero? Line to (x1,y1).
      else if (!(Math.abs(y01 * x21 - y21 * x01) > epsilon$3) || !r) {
        this._append`L${this._x1 = x1},${this._y1 = y1}`;
      }

      // Otherwise, draw an arc!
      else {
        let x20 = x2 - x0,
            y20 = y2 - y0,
            l21_2 = x21 * x21 + y21 * y21,
            l20_2 = x20 * x20 + y20 * y20,
            l21 = Math.sqrt(l21_2),
            l01 = Math.sqrt(l01_2),
            l = r * Math.tan((pi$2 - Math.acos((l21_2 + l01_2 - l20_2) / (2 * l21 * l01))) / 2),
            t01 = l / l01,
            t21 = l / l21;

        // If the start tangent is not coincident with (x0,y0), line to.
        if (Math.abs(t01 - 1) > epsilon$3) {
          this._append`L${x1 + t01 * x01},${y1 + t01 * y01}`;
        }

        this._append`A${r},${r},0,0,${+(y01 * x20 > x01 * y20)},${this._x1 = x1 + t21 * x21},${this._y1 = y1 + t21 * y21}`;
      }
    }
    arc(x, y, r, a0, a1, ccw) {
      x = +x, y = +y, r = +r, ccw = !!ccw;

      // Is the radius negative? Error.
      if (r < 0) throw new Error(`negative radius: ${r}`);

      let dx = r * Math.cos(a0),
          dy = r * Math.sin(a0),
          x0 = x + dx,
          y0 = y + dy,
          cw = 1 ^ ccw,
          da = ccw ? a0 - a1 : a1 - a0;

      // Is this path empty? Move to (x0,y0).
      if (this._x1 === null) {
        this._append`M${x0},${y0}`;
      }

      // Or, is (x0,y0) not coincident with the previous point? Line to (x0,y0).
      else if (Math.abs(this._x1 - x0) > epsilon$3 || Math.abs(this._y1 - y0) > epsilon$3) {
        this._append`L${x0},${y0}`;
      }

      // Is this arc empty? We’re done.
      if (!r) return;

      // Does the angle go the wrong way? Flip the direction.
      if (da < 0) da = da % tau$2 + tau$2;

      // Is this a complete circle? Draw two arcs to complete the circle.
      if (da > tauEpsilon$1) {
        this._append`A${r},${r},0,1,${cw},${x - dx},${y - dy}A${r},${r},0,1,${cw},${this._x1 = x0},${this._y1 = y0}`;
      }

      // Is this arc non-empty? Draw an arc!
      else if (da > epsilon$3) {
        this._append`A${r},${r},0,${+(da >= pi$2)},${cw},${this._x1 = x + r * Math.cos(a1)},${this._y1 = y + r * Math.sin(a1)}`;
      }
    }
    rect(x, y, w, h) {
      this._append`M${this._x0 = this._x1 = +x},${this._y0 = this._y1 = +y}h${w = +w}v${+h}h${-w}Z`;
    }
    toString() {
      return this._;
    }
  };

  function withPath(shape) {
    let digits = 3;

    shape.digits = function(_) {
      if (!arguments.length) return digits;
      if (_ == null) {
        digits = null;
      } else {
        const d = Math.floor(_);
        if (!(d >= 0)) throw new RangeError(`invalid digits: ${_}`);
        digits = d;
      }
      return shape;
    };

    return () => new Path$1(digits);
  }

  function arcInnerRadius(d) {
    return d.innerRadius;
  }

  function arcOuterRadius(d) {
    return d.outerRadius;
  }

  function arcStartAngle(d) {
    return d.startAngle;
  }

  function arcEndAngle(d) {
    return d.endAngle;
  }

  function arcPadAngle(d) {
    return d && d.padAngle; // Note: optional!
  }

  function intersect(x0, y0, x1, y1, x2, y2, x3, y3) {
    var x10 = x1 - x0, y10 = y1 - y0,
        x32 = x3 - x2, y32 = y3 - y2,
        t = y32 * x10 - x32 * y10;
    if (t * t < epsilon$4) return;
    t = (x32 * (y0 - y2) - y32 * (x0 - x2)) / t;
    return [x0 + t * x10, y0 + t * y10];
  }

  // Compute perpendicular offset line of length rc.
  // http://mathworld.wolfram.com/Circle-LineIntersection.html
  function cornerTangents(x0, y0, x1, y1, r1, rc, cw) {
    var x01 = x0 - x1,
        y01 = y0 - y1,
        lo = (cw ? rc : -rc) / sqrt(x01 * x01 + y01 * y01),
        ox = lo * y01,
        oy = -lo * x01,
        x11 = x0 + ox,
        y11 = y0 + oy,
        x10 = x1 + ox,
        y10 = y1 + oy,
        x00 = (x11 + x10) / 2,
        y00 = (y11 + y10) / 2,
        dx = x10 - x11,
        dy = y10 - y11,
        d2 = dx * dx + dy * dy,
        r = r1 - rc,
        D = x11 * y10 - x10 * y11,
        d = (dy < 0 ? -1 : 1) * sqrt(max$2(0, r * r * d2 - D * D)),
        cx0 = (D * dy - dx * d) / d2,
        cy0 = (-D * dx - dy * d) / d2,
        cx1 = (D * dy + dx * d) / d2,
        cy1 = (-D * dx + dy * d) / d2,
        dx0 = cx0 - x00,
        dy0 = cy0 - y00,
        dx1 = cx1 - x00,
        dy1 = cy1 - y00;

    // Pick the closer of the two intersection points.
    // TODO Is there a faster way to determine which intersection to use?
    if (dx0 * dx0 + dy0 * dy0 > dx1 * dx1 + dy1 * dy1) cx0 = cx1, cy0 = cy1;

    return {
      cx: cx0,
      cy: cy0,
      x01: -ox,
      y01: -oy,
      x11: cx0 * (r1 / r - 1),
      y11: cy0 * (r1 / r - 1)
    };
  }

  function arc() {
    var innerRadius = arcInnerRadius,
        outerRadius = arcOuterRadius,
        cornerRadius = constant$4(0),
        padRadius = null,
        startAngle = arcStartAngle,
        endAngle = arcEndAngle,
        padAngle = arcPadAngle,
        context = null,
        path = withPath(arc);

    function arc() {
      var buffer,
          r,
          r0 = +innerRadius.apply(this, arguments),
          r1 = +outerRadius.apply(this, arguments),
          a0 = startAngle.apply(this, arguments) - halfPi$1,
          a1 = endAngle.apply(this, arguments) - halfPi$1,
          da = abs$2(a1 - a0),
          cw = a1 > a0;

      if (!context) context = buffer = path();

      // Ensure that the outer radius is always larger than the inner radius.
      if (r1 < r0) r = r1, r1 = r0, r0 = r;

      // Is it a point?
      if (!(r1 > epsilon$4)) context.moveTo(0, 0);

      // Or is it a circle or annulus?
      else if (da > tau$3 - epsilon$4) {
        context.moveTo(r1 * cos$1(a0), r1 * sin$1(a0));
        context.arc(0, 0, r1, a0, a1, !cw);
        if (r0 > epsilon$4) {
          context.moveTo(r0 * cos$1(a1), r0 * sin$1(a1));
          context.arc(0, 0, r0, a1, a0, cw);
        }
      }

      // Or is it a circular or annular sector?
      else {
        var a01 = a0,
            a11 = a1,
            a00 = a0,
            a10 = a1,
            da0 = da,
            da1 = da,
            ap = padAngle.apply(this, arguments) / 2,
            rp = (ap > epsilon$4) && (padRadius ? +padRadius.apply(this, arguments) : sqrt(r0 * r0 + r1 * r1)),
            rc = min$1(abs$2(r1 - r0) / 2, +cornerRadius.apply(this, arguments)),
            rc0 = rc,
            rc1 = rc,
            t0,
            t1;

        // Apply padding? Note that since r1 ≥ r0, da1 ≥ da0.
        if (rp > epsilon$4) {
          var p0 = asin(rp / r0 * sin$1(ap)),
              p1 = asin(rp / r1 * sin$1(ap));
          if ((da0 -= p0 * 2) > epsilon$4) p0 *= (cw ? 1 : -1), a00 += p0, a10 -= p0;
          else da0 = 0, a00 = a10 = (a0 + a1) / 2;
          if ((da1 -= p1 * 2) > epsilon$4) p1 *= (cw ? 1 : -1), a01 += p1, a11 -= p1;
          else da1 = 0, a01 = a11 = (a0 + a1) / 2;
        }

        var x01 = r1 * cos$1(a01),
            y01 = r1 * sin$1(a01),
            x10 = r0 * cos$1(a10),
            y10 = r0 * sin$1(a10);

        // Apply rounded corners?
        if (rc > epsilon$4) {
          var x11 = r1 * cos$1(a11),
              y11 = r1 * sin$1(a11),
              x00 = r0 * cos$1(a00),
              y00 = r0 * sin$1(a00),
              oc;

          // Restrict the corner radius according to the sector angle. If this
          // intersection fails, it’s probably because the arc is too small, so
          // disable the corner radius entirely.
          if (da < pi$3) {
            if (oc = intersect(x01, y01, x00, y00, x11, y11, x10, y10)) {
              var ax = x01 - oc[0],
                  ay = y01 - oc[1],
                  bx = x11 - oc[0],
                  by = y11 - oc[1],
                  kc = 1 / sin$1(acos((ax * bx + ay * by) / (sqrt(ax * ax + ay * ay) * sqrt(bx * bx + by * by))) / 2),
                  lc = sqrt(oc[0] * oc[0] + oc[1] * oc[1]);
              rc0 = min$1(rc, (r0 - lc) / (kc - 1));
              rc1 = min$1(rc, (r1 - lc) / (kc + 1));
            } else {
              rc0 = rc1 = 0;
            }
          }
        }

        // Is the sector collapsed to a line?
        if (!(da1 > epsilon$4)) context.moveTo(x01, y01);

        // Does the sector’s outer ring have rounded corners?
        else if (rc1 > epsilon$4) {
          t0 = cornerTangents(x00, y00, x01, y01, r1, rc1, cw);
          t1 = cornerTangents(x11, y11, x10, y10, r1, rc1, cw);

          context.moveTo(t0.cx + t0.x01, t0.cy + t0.y01);

          // Have the corners merged?
          if (rc1 < rc) context.arc(t0.cx, t0.cy, rc1, atan2(t0.y01, t0.x01), atan2(t1.y01, t1.x01), !cw);

          // Otherwise, draw the two corners and the ring.
          else {
            context.arc(t0.cx, t0.cy, rc1, atan2(t0.y01, t0.x01), atan2(t0.y11, t0.x11), !cw);
            context.arc(0, 0, r1, atan2(t0.cy + t0.y11, t0.cx + t0.x11), atan2(t1.cy + t1.y11, t1.cx + t1.x11), !cw);
            context.arc(t1.cx, t1.cy, rc1, atan2(t1.y11, t1.x11), atan2(t1.y01, t1.x01), !cw);
          }
        }

        // Or is the outer ring just a circular arc?
        else context.moveTo(x01, y01), context.arc(0, 0, r1, a01, a11, !cw);

        // Is there no inner ring, and it’s a circular sector?
        // Or perhaps it’s an annular sector collapsed due to padding?
        if (!(r0 > epsilon$4) || !(da0 > epsilon$4)) context.lineTo(x10, y10);

        // Does the sector’s inner ring (or point) have rounded corners?
        else if (rc0 > epsilon$4) {
          t0 = cornerTangents(x10, y10, x11, y11, r0, -rc0, cw);
          t1 = cornerTangents(x01, y01, x00, y00, r0, -rc0, cw);

          context.lineTo(t0.cx + t0.x01, t0.cy + t0.y01);

          // Have the corners merged?
          if (rc0 < rc) context.arc(t0.cx, t0.cy, rc0, atan2(t0.y01, t0.x01), atan2(t1.y01, t1.x01), !cw);

          // Otherwise, draw the two corners and the ring.
          else {
            context.arc(t0.cx, t0.cy, rc0, atan2(t0.y01, t0.x01), atan2(t0.y11, t0.x11), !cw);
            context.arc(0, 0, r0, atan2(t0.cy + t0.y11, t0.cx + t0.x11), atan2(t1.cy + t1.y11, t1.cx + t1.x11), cw);
            context.arc(t1.cx, t1.cy, rc0, atan2(t1.y11, t1.x11), atan2(t1.y01, t1.x01), !cw);
          }
        }

        // Or is the inner ring just a circular arc?
        else context.arc(0, 0, r0, a10, a00, cw);
      }

      context.closePath();

      if (buffer) return context = null, buffer + "" || null;
    }

    arc.centroid = function() {
      var r = (+innerRadius.apply(this, arguments) + +outerRadius.apply(this, arguments)) / 2,
          a = (+startAngle.apply(this, arguments) + +endAngle.apply(this, arguments)) / 2 - pi$3 / 2;
      return [cos$1(a) * r, sin$1(a) * r];
    };

    arc.innerRadius = function(_) {
      return arguments.length ? (innerRadius = typeof _ === "function" ? _ : constant$4(+_), arc) : innerRadius;
    };

    arc.outerRadius = function(_) {
      return arguments.length ? (outerRadius = typeof _ === "function" ? _ : constant$4(+_), arc) : outerRadius;
    };

    arc.cornerRadius = function(_) {
      return arguments.length ? (cornerRadius = typeof _ === "function" ? _ : constant$4(+_), arc) : cornerRadius;
    };

    arc.padRadius = function(_) {
      return arguments.length ? (padRadius = _ == null ? null : typeof _ === "function" ? _ : constant$4(+_), arc) : padRadius;
    };

    arc.startAngle = function(_) {
      return arguments.length ? (startAngle = typeof _ === "function" ? _ : constant$4(+_), arc) : startAngle;
    };

    arc.endAngle = function(_) {
      return arguments.length ? (endAngle = typeof _ === "function" ? _ : constant$4(+_), arc) : endAngle;
    };

    arc.padAngle = function(_) {
      return arguments.length ? (padAngle = typeof _ === "function" ? _ : constant$4(+_), arc) : padAngle;
    };

    arc.context = function(_) {
      return arguments.length ? ((context = _ == null ? null : _), arc) : context;
    };

    return arc;
  }

  var slice$1 = Array.prototype.slice;

  function array(x) {
    return typeof x === "object" && "length" in x
      ? x // Array, TypedArray, NodeList, array-like
      : Array.from(x); // Map, Set, iterable, string, or anything else
  }

  function Linear(context) {
    this._context = context;
  }

  Linear.prototype = {
    areaStart: function() {
      this._line = 0;
    },
    areaEnd: function() {
      this._line = NaN;
    },
    lineStart: function() {
      this._point = 0;
    },
    lineEnd: function() {
      if (this._line || (this._line !== 0 && this._point === 1)) this._context.closePath();
      this._line = 1 - this._line;
    },
    point: function(x, y) {
      x = +x, y = +y;
      switch (this._point) {
        case 0: this._point = 1; this._line ? this._context.lineTo(x, y) : this._context.moveTo(x, y); break;
        case 1: this._point = 2; // falls through
        default: this._context.lineTo(x, y); break;
      }
    }
  };

  function curveLinear(context) {
    return new Linear(context);
  }

  function x(p) {
    return p[0];
  }

  function y(p) {
    return p[1];
  }

  function line(x$1, y$1) {
    var defined = constant$4(true),
        context = null,
        curve = curveLinear,
        output = null,
        path = withPath(line);

    x$1 = typeof x$1 === "function" ? x$1 : (x$1 === undefined) ? x : constant$4(x$1);
    y$1 = typeof y$1 === "function" ? y$1 : (y$1 === undefined) ? y : constant$4(y$1);

    function line(data) {
      var i,
          n = (data = array(data)).length,
          d,
          defined0 = false,
          buffer;

      if (context == null) output = curve(buffer = path());

      for (i = 0; i <= n; ++i) {
        if (!(i < n && defined(d = data[i], i, data)) === defined0) {
          if (defined0 = !defined0) output.lineStart();
          else output.lineEnd();
        }
        if (defined0) output.point(+x$1(d, i, data), +y$1(d, i, data));
      }

      if (buffer) return output = null, buffer + "" || null;
    }

    line.x = function(_) {
      return arguments.length ? (x$1 = typeof _ === "function" ? _ : constant$4(+_), line) : x$1;
    };

    line.y = function(_) {
      return arguments.length ? (y$1 = typeof _ === "function" ? _ : constant$4(+_), line) : y$1;
    };

    line.defined = function(_) {
      return arguments.length ? (defined = typeof _ === "function" ? _ : constant$4(!!_), line) : defined;
    };

    line.curve = function(_) {
      return arguments.length ? (curve = _, context != null && (output = curve(context)), line) : curve;
    };

    line.context = function(_) {
      return arguments.length ? (_ == null ? context = output = null : output = curve(context = _), line) : context;
    };

    return line;
  }

  function area(x0, y0, y1) {
    var x1 = null,
        defined = constant$4(true),
        context = null,
        curve = curveLinear,
        output = null,
        path = withPath(area);

    x0 = typeof x0 === "function" ? x0 : (x0 === undefined) ? x : constant$4(+x0);
    y0 = typeof y0 === "function" ? y0 : (y0 === undefined) ? constant$4(0) : constant$4(+y0);
    y1 = typeof y1 === "function" ? y1 : (y1 === undefined) ? y : constant$4(+y1);

    function area(data) {
      var i,
          j,
          k,
          n = (data = array(data)).length,
          d,
          defined0 = false,
          buffer,
          x0z = new Array(n),
          y0z = new Array(n);

      if (context == null) output = curve(buffer = path());

      for (i = 0; i <= n; ++i) {
        if (!(i < n && defined(d = data[i], i, data)) === defined0) {
          if (defined0 = !defined0) {
            j = i;
            output.areaStart();
            output.lineStart();
          } else {
            output.lineEnd();
            output.lineStart();
            for (k = i - 1; k >= j; --k) {
              output.point(x0z[k], y0z[k]);
            }
            output.lineEnd();
            output.areaEnd();
          }
        }
        if (defined0) {
          x0z[i] = +x0(d, i, data), y0z[i] = +y0(d, i, data);
          output.point(x1 ? +x1(d, i, data) : x0z[i], y1 ? +y1(d, i, data) : y0z[i]);
        }
      }

      if (buffer) return output = null, buffer + "" || null;
    }

    function arealine() {
      return line().defined(defined).curve(curve).context(context);
    }

    area.x = function(_) {
      return arguments.length ? (x0 = typeof _ === "function" ? _ : constant$4(+_), x1 = null, area) : x0;
    };

    area.x0 = function(_) {
      return arguments.length ? (x0 = typeof _ === "function" ? _ : constant$4(+_), area) : x0;
    };

    area.x1 = function(_) {
      return arguments.length ? (x1 = _ == null ? null : typeof _ === "function" ? _ : constant$4(+_), area) : x1;
    };

    area.y = function(_) {
      return arguments.length ? (y0 = typeof _ === "function" ? _ : constant$4(+_), y1 = null, area) : y0;
    };

    area.y0 = function(_) {
      return arguments.length ? (y0 = typeof _ === "function" ? _ : constant$4(+_), area) : y0;
    };

    area.y1 = function(_) {
      return arguments.length ? (y1 = _ == null ? null : typeof _ === "function" ? _ : constant$4(+_), area) : y1;
    };

    area.lineX0 =
    area.lineY0 = function() {
      return arealine().x(x0).y(y0);
    };

    area.lineY1 = function() {
      return arealine().x(x0).y(y1);
    };

    area.lineX1 = function() {
      return arealine().x(x1).y(y0);
    };

    area.defined = function(_) {
      return arguments.length ? (defined = typeof _ === "function" ? _ : constant$4(!!_), area) : defined;
    };

    area.curve = function(_) {
      return arguments.length ? (curve = _, context != null && (output = curve(context)), area) : curve;
    };

    area.context = function(_) {
      return arguments.length ? (_ == null ? context = output = null : output = curve(context = _), area) : context;
    };

    return area;
  }

  function descending(a, b) {
    return b < a ? -1 : b > a ? 1 : b >= a ? 0 : NaN;
  }

  function identity$1(d) {
    return d;
  }

  function pie() {
    var value = identity$1,
        sortValues = descending,
        sort = null,
        startAngle = constant$4(0),
        endAngle = constant$4(tau$3),
        padAngle = constant$4(0);

    function pie(data) {
      var i,
          n = (data = array(data)).length,
          j,
          k,
          sum = 0,
          index = new Array(n),
          arcs = new Array(n),
          a0 = +startAngle.apply(this, arguments),
          da = Math.min(tau$3, Math.max(-tau$3, endAngle.apply(this, arguments) - a0)),
          a1,
          p = Math.min(Math.abs(da) / n, padAngle.apply(this, arguments)),
          pa = p * (da < 0 ? -1 : 1),
          v;

      for (i = 0; i < n; ++i) {
        if ((v = arcs[index[i] = i] = +value(data[i], i, data)) > 0) {
          sum += v;
        }
      }

      // Optionally sort the arcs by previously-computed values or by data.
      if (sortValues != null) index.sort(function(i, j) { return sortValues(arcs[i], arcs[j]); });
      else if (sort != null) index.sort(function(i, j) { return sort(data[i], data[j]); });

      // Compute the arcs! They are stored in the original data's order.
      for (i = 0, k = sum ? (da - n * pa) / sum : 0; i < n; ++i, a0 = a1) {
        j = index[i], v = arcs[j], a1 = a0 + (v > 0 ? v * k : 0) + pa, arcs[j] = {
          data: data[j],
          index: i,
          value: v,
          startAngle: a0,
          endAngle: a1,
          padAngle: p
        };
      }

      return arcs;
    }

    pie.value = function(_) {
      return arguments.length ? (value = typeof _ === "function" ? _ : constant$4(+_), pie) : value;
    };

    pie.sortValues = function(_) {
      return arguments.length ? (sortValues = _, sort = null, pie) : sortValues;
    };

    pie.sort = function(_) {
      return arguments.length ? (sort = _, sortValues = null, pie) : sort;
    };

    pie.startAngle = function(_) {
      return arguments.length ? (startAngle = typeof _ === "function" ? _ : constant$4(+_), pie) : startAngle;
    };

    pie.endAngle = function(_) {
      return arguments.length ? (endAngle = typeof _ === "function" ? _ : constant$4(+_), pie) : endAngle;
    };

    pie.padAngle = function(_) {
      return arguments.length ? (padAngle = typeof _ === "function" ? _ : constant$4(+_), pie) : padAngle;
    };

    return pie;
  }

  class Bump {
    constructor(context, x) {
      this._context = context;
      this._x = x;
    }
    areaStart() {
      this._line = 0;
    }
    areaEnd() {
      this._line = NaN;
    }
    lineStart() {
      this._point = 0;
    }
    lineEnd() {
      if (this._line || (this._line !== 0 && this._point === 1)) this._context.closePath();
      this._line = 1 - this._line;
    }
    point(x, y) {
      x = +x, y = +y;
      switch (this._point) {
        case 0: {
          this._point = 1;
          if (this._line) this._context.lineTo(x, y);
          else this._context.moveTo(x, y);
          break;
        }
        case 1: this._point = 2; // falls through
        default: {
          if (this._x) this._context.bezierCurveTo(this._x0 = (this._x0 + x) / 2, this._y0, this._x0, y, x, y);
          else this._context.bezierCurveTo(this._x0, this._y0 = (this._y0 + y) / 2, x, this._y0, x, y);
          break;
        }
      }
      this._x0 = x, this._y0 = y;
    }
  }

  function bumpX(context) {
    return new Bump(context, true);
  }

  function linkSource(d) {
    return d.source;
  }

  function linkTarget(d) {
    return d.target;
  }

  function link(curve) {
    let source = linkSource,
        target = linkTarget,
        x$1 = x,
        y$1 = y,
        context = null,
        output = null,
        path = withPath(link);

    function link() {
      let buffer;
      const argv = slice$1.call(arguments);
      const s = source.apply(this, argv);
      const t = target.apply(this, argv);
      if (context == null) output = curve(buffer = path());
      output.lineStart();
      argv[0] = s, output.point(+x$1.apply(this, argv), +y$1.apply(this, argv));
      argv[0] = t, output.point(+x$1.apply(this, argv), +y$1.apply(this, argv));
      output.lineEnd();
      if (buffer) return output = null, buffer + "" || null;
    }

    link.source = function(_) {
      return arguments.length ? (source = _, link) : source;
    };

    link.target = function(_) {
      return arguments.length ? (target = _, link) : target;
    };

    link.x = function(_) {
      return arguments.length ? (x$1 = typeof _ === "function" ? _ : constant$4(+_), link) : x$1;
    };

    link.y = function(_) {
      return arguments.length ? (y$1 = typeof _ === "function" ? _ : constant$4(+_), link) : y$1;
    };

    link.context = function(_) {
      return arguments.length ? (_ == null ? context = output = null : output = curve(context = _), link) : context;
    };

    return link;
  }

  function linkHorizontal() {
    return link(bumpX);
  }

  function point$1(that, x, y) {
    that._context.bezierCurveTo(
      (2 * that._x0 + that._x1) / 3,
      (2 * that._y0 + that._y1) / 3,
      (that._x0 + 2 * that._x1) / 3,
      (that._y0 + 2 * that._y1) / 3,
      (that._x0 + 4 * that._x1 + x) / 6,
      (that._y0 + 4 * that._y1 + y) / 6
    );
  }

  function Basis(context) {
    this._context = context;
  }

  Basis.prototype = {
    areaStart: function() {
      this._line = 0;
    },
    areaEnd: function() {
      this._line = NaN;
    },
    lineStart: function() {
      this._x0 = this._x1 =
      this._y0 = this._y1 = NaN;
      this._point = 0;
    },
    lineEnd: function() {
      switch (this._point) {
        case 3: point$1(this, this._x1, this._y1); // falls through
        case 2: this._context.lineTo(this._x1, this._y1); break;
      }
      if (this._line || (this._line !== 0 && this._point === 1)) this._context.closePath();
      this._line = 1 - this._line;
    },
    point: function(x, y) {
      x = +x, y = +y;
      switch (this._point) {
        case 0: this._point = 1; this._line ? this._context.lineTo(x, y) : this._context.moveTo(x, y); break;
        case 1: this._point = 2; break;
        case 2: this._point = 3; this._context.lineTo((5 * this._x0 + this._x1) / 6, (5 * this._y0 + this._y1) / 6); // falls through
        default: point$1(this, x, y); break;
      }
      this._x0 = this._x1, this._x1 = x;
      this._y0 = this._y1, this._y1 = y;
    }
  };

  function curveBasis(context) {
    return new Basis(context);
  }

  function sign(x) {
    return x < 0 ? -1 : 1;
  }

  // Calculate the slopes of the tangents (Hermite-type interpolation) based on
  // the following paper: Steffen, M. 1990. A Simple Method for Monotonic
  // Interpolation in One Dimension. Astronomy and Astrophysics, Vol. 239, NO.
  // NOV(II), P. 443, 1990.
  function slope3(that, x2, y2) {
    var h0 = that._x1 - that._x0,
        h1 = x2 - that._x1,
        s0 = (that._y1 - that._y0) / (h0 || h1 < 0 && -0),
        s1 = (y2 - that._y1) / (h1 || h0 < 0 && -0),
        p = (s0 * h1 + s1 * h0) / (h0 + h1);
    return (sign(s0) + sign(s1)) * Math.min(Math.abs(s0), Math.abs(s1), 0.5 * Math.abs(p)) || 0;
  }

  // Calculate a one-sided slope.
  function slope2(that, t) {
    var h = that._x1 - that._x0;
    return h ? (3 * (that._y1 - that._y0) / h - t) / 2 : t;
  }

  // According to https://en.wikipedia.org/wiki/Cubic_Hermite_spline#Representations
  // "you can express cubic Hermite interpolation in terms of cubic Bézier curves
  // with respect to the four values p0, p0 + m0 / 3, p1 - m1 / 3, p1".
  function point(that, t0, t1) {
    var x0 = that._x0,
        y0 = that._y0,
        x1 = that._x1,
        y1 = that._y1,
        dx = (x1 - x0) / 3;
    that._context.bezierCurveTo(x0 + dx, y0 + dx * t0, x1 - dx, y1 - dx * t1, x1, y1);
  }

  function MonotoneX(context) {
    this._context = context;
  }

  MonotoneX.prototype = {
    areaStart: function() {
      this._line = 0;
    },
    areaEnd: function() {
      this._line = NaN;
    },
    lineStart: function() {
      this._x0 = this._x1 =
      this._y0 = this._y1 =
      this._t0 = NaN;
      this._point = 0;
    },
    lineEnd: function() {
      switch (this._point) {
        case 2: this._context.lineTo(this._x1, this._y1); break;
        case 3: point(this, this._t0, slope2(this, this._t0)); break;
      }
      if (this._line || (this._line !== 0 && this._point === 1)) this._context.closePath();
      this._line = 1 - this._line;
    },
    point: function(x, y) {
      var t1 = NaN;

      x = +x, y = +y;
      if (x === this._x1 && y === this._y1) return; // Ignore coincident points.
      switch (this._point) {
        case 0: this._point = 1; this._line ? this._context.lineTo(x, y) : this._context.moveTo(x, y); break;
        case 1: this._point = 2; break;
        case 2: this._point = 3; point(this, slope2(this, t1 = slope3(this, x, y)), t1); break;
        default: point(this, this._t0, t1 = slope3(this, x, y)); break;
      }

      this._x0 = this._x1, this._x1 = x;
      this._y0 = this._y1, this._y1 = y;
      this._t0 = t1;
    }
  };

  (Object.create(MonotoneX.prototype)).point = function(x, y) {
    MonotoneX.prototype.point.call(this, y, x);
  };

  function monotoneX(context) {
    return new MonotoneX(context);
  }

  function none$1(series, order) {
    if (!((n = series.length) > 1)) return;
    for (var i = 1, j, s0, s1 = series[order[0]], n, m = s1.length; i < n; ++i) {
      s0 = s1, s1 = series[order[i]];
      for (j = 0; j < m; ++j) {
        s1[j][1] += s1[j][0] = isNaN(s0[j][1]) ? s0[j][0] : s0[j][1];
      }
    }
  }

  function none(series) {
    var n = series.length, o = new Array(n);
    while (--n >= 0) o[n] = n;
    return o;
  }

  function stackValue(d, key) {
    return d[key];
  }

  function stackSeries(key) {
    const series = [];
    series.key = key;
    return series;
  }

  function stack() {
    var keys = constant$4([]),
        order = none,
        offset = none$1,
        value = stackValue;

    function stack(data) {
      var sz = Array.from(keys.apply(this, arguments), stackSeries),
          i, n = sz.length, j = -1,
          oz;

      for (const d of data) {
        for (i = 0, ++j; i < n; ++i) {
          (sz[i][j] = [0, +value(d, sz[i].key, j, data)]).data = d;
        }
      }

      for (i = 0, oz = array(order(sz)); i < n; ++i) {
        sz[oz[i]].index = i;
      }

      offset(sz, oz);
      return sz;
    }

    stack.keys = function(_) {
      return arguments.length ? (keys = typeof _ === "function" ? _ : constant$4(Array.from(_)), stack) : keys;
    };

    stack.value = function(_) {
      return arguments.length ? (value = typeof _ === "function" ? _ : constant$4(+_), stack) : value;
    };

    stack.order = function(_) {
      return arguments.length ? (order = _ == null ? none : typeof _ === "function" ? _ : constant$4(Array.from(_)), stack) : order;
    };

    stack.offset = function(_) {
      return arguments.length ? (offset = _ == null ? none$1 : _, stack) : offset;
    };

    return stack;
  }

  function stackOffsetSilhouette(series, order) {
    if (!((n = series.length) > 0)) return;
    for (var j = 0, s0 = series[order[0]], n, m = s0.length; j < m; ++j) {
      for (var i = 0, y = 0; i < n; ++i) y += series[i][j][1] || 0;
      s0[j][1] += s0[j][0] = -y / 2;
    }
    none$1(series, order);
  }

  function appearance(series) {
    var peaks = series.map(peak);
    return none(series).sort(function(a, b) { return peaks[a] - peaks[b]; });
  }

  function peak(series) {
    var i = -1, j = 0, n = series.length, vi, vj = -Infinity;
    while (++i < n) if ((vi = +series[i][1]) > vj) vj = vi, j = i;
    return j;
  }

  function sum(series) {
    var s = 0, i = -1, n = series.length, v;
    while (++i < n) if (v = +series[i][1]) s += v;
    return s;
  }

  function stackOrderInsideOut(series) {
    var n = series.length,
        i,
        j,
        sums = series.map(sum),
        order = appearance(series),
        top = 0,
        bottom = 0,
        tops = [],
        bottoms = [];

    for (i = 0; i < n; ++i) {
      j = order[i];
      if (top < bottom) {
        top += sums[j];
        tops.push(j);
      } else {
        bottom += sums[j];
        bottoms.push(j);
      }
    }

    return bottoms.reverse().concat(tops);
  }

  var noop$1 = {value: () => {}};

  function dispatch$1() {
    for (var i = 0, n = arguments.length, _ = {}, t; i < n; ++i) {
      if (!(t = arguments[i] + "") || (t in _) || /[\s.]/.test(t)) throw new Error("illegal type: " + t);
      _[t] = [];
    }
    return new Dispatch$1(_);
  }

  function Dispatch$1(_) {
    this._ = _;
  }

  function parseTypenames$1(typenames, types) {
    return typenames.trim().split(/^|\s+/).map(function(t) {
      var name = "", i = t.indexOf(".");
      if (i >= 0) name = t.slice(i + 1), t = t.slice(0, i);
      if (t && !types.hasOwnProperty(t)) throw new Error("unknown type: " + t);
      return {type: t, name: name};
    });
  }

  Dispatch$1.prototype = dispatch$1.prototype = {
    constructor: Dispatch$1,
    on: function(typename, callback) {
      var _ = this._,
          T = parseTypenames$1(typename + "", _),
          t,
          i = -1,
          n = T.length;

      // If no callback was specified, return the callback of the given type and name.
      if (arguments.length < 2) {
        while (++i < n) if ((t = (typename = T[i]).type) && (t = get$2(_[t], typename.name))) return t;
        return;
      }

      // If a type was specified, set the callback for the given type and name.
      // Otherwise, if a null callback was specified, remove callbacks of the given name.
      if (callback != null && typeof callback !== "function") throw new Error("invalid callback: " + callback);
      while (++i < n) {
        if (t = (typename = T[i]).type) _[t] = set$2(_[t], typename.name, callback);
        else if (callback == null) for (t in _) _[t] = set$2(_[t], typename.name, null);
      }

      return this;
    },
    copy: function() {
      var copy = {}, _ = this._;
      for (var t in _) copy[t] = _[t].slice();
      return new Dispatch$1(copy);
    },
    call: function(type, that) {
      if ((n = arguments.length - 2) > 0) for (var args = new Array(n), i = 0, n, t; i < n; ++i) args[i] = arguments[i + 2];
      if (!this._.hasOwnProperty(type)) throw new Error("unknown type: " + type);
      for (t = this._[type], i = 0, n = t.length; i < n; ++i) t[i].value.apply(that, args);
    },
    apply: function(type, that, args) {
      if (!this._.hasOwnProperty(type)) throw new Error("unknown type: " + type);
      for (var t = this._[type], i = 0, n = t.length; i < n; ++i) t[i].value.apply(that, args);
    }
  };

  function get$2(type, name) {
    for (var i = 0, n = type.length, c; i < n; ++i) {
      if ((c = type[i]).name === name) {
        return c.value;
      }
    }
  }

  function set$2(type, name, callback) {
    for (var i = 0, n = type.length; i < n; ++i) {
      if (type[i].name === name) {
        type[i] = noop$1, type = type.slice(0, i).concat(type.slice(i + 1));
        break;
      }
    }
    if (callback != null) type.push({name: name, value: callback});
    return type;
  }

  var frame = 0, // is an animation frame pending?
      timeout$1 = 0, // is a timeout pending?
      interval = 0, // are any timers active?
      pokeDelay = 1000, // how frequently we check for clock skew
      taskHead,
      taskTail,
      clockLast = 0,
      clockNow = 0,
      clockSkew = 0,
      clock = typeof performance === "object" && performance.now ? performance : Date,
      setFrame = typeof window === "object" && window.requestAnimationFrame ? window.requestAnimationFrame.bind(window) : function(f) { setTimeout(f, 17); };

  function now() {
    return clockNow || (setFrame(clearNow), clockNow = clock.now() + clockSkew);
  }

  function clearNow() {
    clockNow = 0;
  }

  function Timer() {
    this._call =
    this._time =
    this._next = null;
  }

  Timer.prototype = timer.prototype = {
    constructor: Timer,
    restart: function(callback, delay, time) {
      if (typeof callback !== "function") throw new TypeError("callback is not a function");
      time = (time == null ? now() : +time) + (delay == null ? 0 : +delay);
      if (!this._next && taskTail !== this) {
        if (taskTail) taskTail._next = this;
        else taskHead = this;
        taskTail = this;
      }
      this._call = callback;
      this._time = time;
      sleep();
    },
    stop: function() {
      if (this._call) {
        this._call = null;
        this._time = Infinity;
        sleep();
      }
    }
  };

  function timer(callback, delay, time) {
    var t = new Timer;
    t.restart(callback, delay, time);
    return t;
  }

  function timerFlush() {
    now(); // Get the current time, if not already set.
    ++frame; // Pretend we’ve set an alarm, if we haven’t already.
    var t = taskHead, e;
    while (t) {
      if ((e = clockNow - t._time) >= 0) t._call.call(undefined, e);
      t = t._next;
    }
    --frame;
  }

  function wake() {
    clockNow = (clockLast = clock.now()) + clockSkew;
    frame = timeout$1 = 0;
    try {
      timerFlush();
    } finally {
      frame = 0;
      nap();
      clockNow = 0;
    }
  }

  function poke() {
    var now = clock.now(), delay = now - clockLast;
    if (delay > pokeDelay) clockSkew -= delay, clockLast = now;
  }

  function nap() {
    var t0, t1 = taskHead, t2, time = Infinity;
    while (t1) {
      if (t1._call) {
        if (time > t1._time) time = t1._time;
        t0 = t1, t1 = t1._next;
      } else {
        t2 = t1._next, t1._next = null;
        t1 = t0 ? t0._next = t2 : taskHead = t2;
      }
    }
    taskTail = t0;
    sleep(time);
  }

  function sleep(time) {
    if (frame) return; // Soonest alarm already set, or will be.
    if (timeout$1) timeout$1 = clearTimeout(timeout$1);
    var delay = time - clockNow; // Strictly less than if we recomputed clockNow.
    if (delay > 24) {
      if (time < Infinity) timeout$1 = setTimeout(wake, time - clock.now() - clockSkew);
      if (interval) interval = clearInterval(interval);
    } else {
      if (!interval) clockLast = clock.now(), interval = setInterval(poke, pokeDelay);
      frame = 1, setFrame(wake);
    }
  }

  function timeout(callback, delay, time) {
    var t = new Timer;
    delay = delay == null ? 0 : +delay;
    t.restart(elapsed => {
      t.stop();
      callback(elapsed + delay);
    }, delay, time);
    return t;
  }

  var emptyOn = dispatch$1("start", "end", "cancel", "interrupt");
  var emptyTween = [];

  var CREATED = 0;
  var SCHEDULED = 1;
  var STARTING = 2;
  var STARTED = 3;
  var RUNNING = 4;
  var ENDING = 5;
  var ENDED = 6;

  function schedule(node, name, id, index, group, timing) {
    var schedules = node.__transition;
    if (!schedules) node.__transition = {};
    else if (id in schedules) return;
    create(node, id, {
      name: name,
      index: index, // For context during callback.
      group: group, // For context during callback.
      on: emptyOn,
      tween: emptyTween,
      time: timing.time,
      delay: timing.delay,
      duration: timing.duration,
      ease: timing.ease,
      timer: null,
      state: CREATED
    });
  }

  function init(node, id) {
    var schedule = get$1(node, id);
    if (schedule.state > CREATED) throw new Error("too late; already scheduled");
    return schedule;
  }

  function set$1(node, id) {
    var schedule = get$1(node, id);
    if (schedule.state > STARTED) throw new Error("too late; already running");
    return schedule;
  }

  function get$1(node, id) {
    var schedule = node.__transition;
    if (!schedule || !(schedule = schedule[id])) throw new Error("transition not found");
    return schedule;
  }

  function create(node, id, self) {
    var schedules = node.__transition,
        tween;

    // Initialize the self timer when the transition is created.
    // Note the actual delay is not known until the first callback!
    schedules[id] = self;
    self.timer = timer(schedule, 0, self.time);

    function schedule(elapsed) {
      self.state = SCHEDULED;
      self.timer.restart(start, self.delay, self.time);

      // If the elapsed delay is less than our first sleep, start immediately.
      if (self.delay <= elapsed) start(elapsed - self.delay);
    }

    function start(elapsed) {
      var i, j, n, o;

      // If the state is not SCHEDULED, then we previously errored on start.
      if (self.state !== SCHEDULED) return stop();

      for (i in schedules) {
        o = schedules[i];
        if (o.name !== self.name) continue;

        // While this element already has a starting transition during this frame,
        // defer starting an interrupting transition until that transition has a
        // chance to tick (and possibly end); see d3/d3-transition#54!
        if (o.state === STARTED) return timeout(start);

        // Interrupt the active transition, if any.
        if (o.state === RUNNING) {
          o.state = ENDED;
          o.timer.stop();
          o.on.call("interrupt", node, node.__data__, o.index, o.group);
          delete schedules[i];
        }

        // Cancel any pre-empted transitions.
        else if (+i < id) {
          o.state = ENDED;
          o.timer.stop();
          o.on.call("cancel", node, node.__data__, o.index, o.group);
          delete schedules[i];
        }
      }

      // Defer the first tick to end of the current frame; see d3/d3#1576.
      // Note the transition may be canceled after start and before the first tick!
      // Note this must be scheduled before the start event; see d3/d3-transition#16!
      // Assuming this is successful, subsequent callbacks go straight to tick.
      timeout(function() {
        if (self.state === STARTED) {
          self.state = RUNNING;
          self.timer.restart(tick, self.delay, self.time);
          tick(elapsed);
        }
      });

      // Dispatch the start event.
      // Note this must be done before the tween are initialized.
      self.state = STARTING;
      self.on.call("start", node, node.__data__, self.index, self.group);
      if (self.state !== STARTING) return; // interrupted
      self.state = STARTED;

      // Initialize the tween, deleting null tween.
      tween = new Array(n = self.tween.length);
      for (i = 0, j = -1; i < n; ++i) {
        if (o = self.tween[i].value.call(node, node.__data__, self.index, self.group)) {
          tween[++j] = o;
        }
      }
      tween.length = j + 1;
    }

    function tick(elapsed) {
      var t = elapsed < self.duration ? self.ease.call(null, elapsed / self.duration) : (self.timer.restart(stop), self.state = ENDING, 1),
          i = -1,
          n = tween.length;

      while (++i < n) {
        tween[i].call(node, t);
      }

      // Dispatch the end event.
      if (self.state === ENDING) {
        self.on.call("end", node, node.__data__, self.index, self.group);
        stop();
      }
    }

    function stop() {
      self.state = ENDED;
      self.timer.stop();
      delete schedules[id];
      for (var i in schedules) return; // eslint-disable-line no-unused-vars
      delete node.__transition;
    }
  }

  function interrupt(node, name) {
    var schedules = node.__transition,
        schedule,
        active,
        empty = true,
        i;

    if (!schedules) return;

    name = name == null ? null : name + "";

    for (i in schedules) {
      if ((schedule = schedules[i]).name !== name) { empty = false; continue; }
      active = schedule.state > STARTING && schedule.state < ENDING;
      schedule.state = ENDED;
      schedule.timer.stop();
      schedule.on.call(active ? "interrupt" : "cancel", node, node.__data__, schedule.index, schedule.group);
      delete schedules[i];
    }

    if (empty) delete node.__transition;
  }

  function selection_interrupt(name) {
    return this.each(function() {
      interrupt(this, name);
    });
  }

  function tweenRemove(id, name) {
    var tween0, tween1;
    return function() {
      var schedule = set$1(this, id),
          tween = schedule.tween;

      // If this node shared tween with the previous node,
      // just assign the updated shared tween and we’re done!
      // Otherwise, copy-on-write.
      if (tween !== tween0) {
        tween1 = tween0 = tween;
        for (var i = 0, n = tween1.length; i < n; ++i) {
          if (tween1[i].name === name) {
            tween1 = tween1.slice();
            tween1.splice(i, 1);
            break;
          }
        }
      }

      schedule.tween = tween1;
    };
  }

  function tweenFunction(id, name, value) {
    var tween0, tween1;
    if (typeof value !== "function") throw new Error;
    return function() {
      var schedule = set$1(this, id),
          tween = schedule.tween;

      // If this node shared tween with the previous node,
      // just assign the updated shared tween and we’re done!
      // Otherwise, copy-on-write.
      if (tween !== tween0) {
        tween1 = (tween0 = tween).slice();
        for (var t = {name: name, value: value}, i = 0, n = tween1.length; i < n; ++i) {
          if (tween1[i].name === name) {
            tween1[i] = t;
            break;
          }
        }
        if (i === n) tween1.push(t);
      }

      schedule.tween = tween1;
    };
  }

  function transition_tween(name, value) {
    var id = this._id;

    name += "";

    if (arguments.length < 2) {
      var tween = get$1(this.node(), id).tween;
      for (var i = 0, n = tween.length, t; i < n; ++i) {
        if ((t = tween[i]).name === name) {
          return t.value;
        }
      }
      return null;
    }

    return this.each((value == null ? tweenRemove : tweenFunction)(id, name, value));
  }

  function tweenValue(transition, name, value) {
    var id = transition._id;

    transition.each(function() {
      var schedule = set$1(this, id);
      (schedule.value || (schedule.value = {}))[name] = value.apply(this, arguments);
    });

    return function(node) {
      return get$1(node, id).value[name];
    };
  }

  function interpolate$1(a, b) {
    var c;
    return (typeof b === "number" ? interpolateNumber
        : b instanceof color$1 ? interpolateRgb$1
        : (c = color$1(b)) ? (b = c, interpolateRgb$1)
        : interpolateString)(a, b);
  }

  function attrRemove(name) {
    return function() {
      this.removeAttribute(name);
    };
  }

  function attrRemoveNS(fullname) {
    return function() {
      this.removeAttributeNS(fullname.space, fullname.local);
    };
  }

  function attrConstant(name, interpolate, value1) {
    var string00,
        string1 = value1 + "",
        interpolate0;
    return function() {
      var string0 = this.getAttribute(name);
      return string0 === string1 ? null
          : string0 === string00 ? interpolate0
          : interpolate0 = interpolate(string00 = string0, value1);
    };
  }

  function attrConstantNS(fullname, interpolate, value1) {
    var string00,
        string1 = value1 + "",
        interpolate0;
    return function() {
      var string0 = this.getAttributeNS(fullname.space, fullname.local);
      return string0 === string1 ? null
          : string0 === string00 ? interpolate0
          : interpolate0 = interpolate(string00 = string0, value1);
    };
  }

  function attrFunction(name, interpolate, value) {
    var string00,
        string10,
        interpolate0;
    return function() {
      var string0, value1 = value(this), string1;
      if (value1 == null) return void this.removeAttribute(name);
      string0 = this.getAttribute(name);
      string1 = value1 + "";
      return string0 === string1 ? null
          : string0 === string00 && string1 === string10 ? interpolate0
          : (string10 = string1, interpolate0 = interpolate(string00 = string0, value1));
    };
  }

  function attrFunctionNS(fullname, interpolate, value) {
    var string00,
        string10,
        interpolate0;
    return function() {
      var string0, value1 = value(this), string1;
      if (value1 == null) return void this.removeAttributeNS(fullname.space, fullname.local);
      string0 = this.getAttributeNS(fullname.space, fullname.local);
      string1 = value1 + "";
      return string0 === string1 ? null
          : string0 === string00 && string1 === string10 ? interpolate0
          : (string10 = string1, interpolate0 = interpolate(string00 = string0, value1));
    };
  }

  function transition_attr(name, value) {
    var fullname = namespace(name), i = fullname === "transform" ? interpolateTransformSvg : interpolate$1;
    return this.attrTween(name, typeof value === "function"
        ? (fullname.local ? attrFunctionNS : attrFunction)(fullname, i, tweenValue(this, "attr." + name, value))
        : value == null ? (fullname.local ? attrRemoveNS : attrRemove)(fullname)
        : (fullname.local ? attrConstantNS : attrConstant)(fullname, i, value));
  }

  function attrInterpolate(name, i) {
    return function(t) {
      this.setAttribute(name, i.call(this, t));
    };
  }

  function attrInterpolateNS(fullname, i) {
    return function(t) {
      this.setAttributeNS(fullname.space, fullname.local, i.call(this, t));
    };
  }

  function attrTweenNS(fullname, value) {
    var t0, i0;
    function tween() {
      var i = value.apply(this, arguments);
      if (i !== i0) t0 = (i0 = i) && attrInterpolateNS(fullname, i);
      return t0;
    }
    tween._value = value;
    return tween;
  }

  function attrTween(name, value) {
    var t0, i0;
    function tween() {
      var i = value.apply(this, arguments);
      if (i !== i0) t0 = (i0 = i) && attrInterpolate(name, i);
      return t0;
    }
    tween._value = value;
    return tween;
  }

  function transition_attrTween(name, value) {
    var key = "attr." + name;
    if (arguments.length < 2) return (key = this.tween(key)) && key._value;
    if (value == null) return this.tween(key, null);
    if (typeof value !== "function") throw new Error;
    var fullname = namespace(name);
    return this.tween(key, (fullname.local ? attrTweenNS : attrTween)(fullname, value));
  }

  function delayFunction(id, value) {
    return function() {
      init(this, id).delay = +value.apply(this, arguments);
    };
  }

  function delayConstant(id, value) {
    return value = +value, function() {
      init(this, id).delay = value;
    };
  }

  function transition_delay(value) {
    var id = this._id;

    return arguments.length
        ? this.each((typeof value === "function"
            ? delayFunction
            : delayConstant)(id, value))
        : get$1(this.node(), id).delay;
  }

  function durationFunction(id, value) {
    return function() {
      set$1(this, id).duration = +value.apply(this, arguments);
    };
  }

  function durationConstant(id, value) {
    return value = +value, function() {
      set$1(this, id).duration = value;
    };
  }

  function transition_duration(value) {
    var id = this._id;

    return arguments.length
        ? this.each((typeof value === "function"
            ? durationFunction
            : durationConstant)(id, value))
        : get$1(this.node(), id).duration;
  }

  function easeConstant(id, value) {
    if (typeof value !== "function") throw new Error;
    return function() {
      set$1(this, id).ease = value;
    };
  }

  function transition_ease(value) {
    var id = this._id;

    return arguments.length
        ? this.each(easeConstant(id, value))
        : get$1(this.node(), id).ease;
  }

  function easeVarying(id, value) {
    return function() {
      var v = value.apply(this, arguments);
      if (typeof v !== "function") throw new Error;
      set$1(this, id).ease = v;
    };
  }

  function transition_easeVarying(value) {
    if (typeof value !== "function") throw new Error;
    return this.each(easeVarying(this._id, value));
  }

  function transition_filter(match) {
    if (typeof match !== "function") match = matcher(match);

    for (var groups = this._groups, m = groups.length, subgroups = new Array(m), j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, subgroup = subgroups[j] = [], node, i = 0; i < n; ++i) {
        if ((node = group[i]) && match.call(node, node.__data__, i, group)) {
          subgroup.push(node);
        }
      }
    }

    return new Transition(subgroups, this._parents, this._name, this._id);
  }

  function transition_merge(transition) {
    if (transition._id !== this._id) throw new Error;

    for (var groups0 = this._groups, groups1 = transition._groups, m0 = groups0.length, m1 = groups1.length, m = Math.min(m0, m1), merges = new Array(m0), j = 0; j < m; ++j) {
      for (var group0 = groups0[j], group1 = groups1[j], n = group0.length, merge = merges[j] = new Array(n), node, i = 0; i < n; ++i) {
        if (node = group0[i] || group1[i]) {
          merge[i] = node;
        }
      }
    }

    for (; j < m0; ++j) {
      merges[j] = groups0[j];
    }

    return new Transition(merges, this._parents, this._name, this._id);
  }

  function start(name) {
    return (name + "").trim().split(/^|\s+/).every(function(t) {
      var i = t.indexOf(".");
      if (i >= 0) t = t.slice(0, i);
      return !t || t === "start";
    });
  }

  function onFunction(id, name, listener) {
    var on0, on1, sit = start(name) ? init : set$1;
    return function() {
      var schedule = sit(this, id),
          on = schedule.on;

      // If this node shared a dispatch with the previous node,
      // just assign the updated shared dispatch and we’re done!
      // Otherwise, copy-on-write.
      if (on !== on0) (on1 = (on0 = on).copy()).on(name, listener);

      schedule.on = on1;
    };
  }

  function transition_on(name, listener) {
    var id = this._id;

    return arguments.length < 2
        ? get$1(this.node(), id).on.on(name)
        : this.each(onFunction(id, name, listener));
  }

  function removeFunction(id) {
    return function() {
      var parent = this.parentNode;
      for (var i in this.__transition) if (+i !== id) return;
      if (parent) parent.removeChild(this);
    };
  }

  function transition_remove() {
    return this.on("end.remove", removeFunction(this._id));
  }

  function transition_select(select) {
    var name = this._name,
        id = this._id;

    if (typeof select !== "function") select = selector(select);

    for (var groups = this._groups, m = groups.length, subgroups = new Array(m), j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, subgroup = subgroups[j] = new Array(n), node, subnode, i = 0; i < n; ++i) {
        if ((node = group[i]) && (subnode = select.call(node, node.__data__, i, group))) {
          if ("__data__" in node) subnode.__data__ = node.__data__;
          subgroup[i] = subnode;
          schedule(subgroup[i], name, id, i, subgroup, get$1(node, id));
        }
      }
    }

    return new Transition(subgroups, this._parents, name, id);
  }

  function transition_selectAll(select) {
    var name = this._name,
        id = this._id;

    if (typeof select !== "function") select = selectorAll(select);

    for (var groups = this._groups, m = groups.length, subgroups = [], parents = [], j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, node, i = 0; i < n; ++i) {
        if (node = group[i]) {
          for (var children = select.call(node, node.__data__, i, group), child, inherit = get$1(node, id), k = 0, l = children.length; k < l; ++k) {
            if (child = children[k]) {
              schedule(child, name, id, k, children, inherit);
            }
          }
          subgroups.push(children);
          parents.push(node);
        }
      }
    }

    return new Transition(subgroups, parents, name, id);
  }

  var Selection = selection.prototype.constructor;

  function transition_selection() {
    return new Selection(this._groups, this._parents);
  }

  function styleNull(name, interpolate) {
    var string00,
        string10,
        interpolate0;
    return function() {
      var string0 = styleValue(this, name),
          string1 = (this.style.removeProperty(name), styleValue(this, name));
      return string0 === string1 ? null
          : string0 === string00 && string1 === string10 ? interpolate0
          : interpolate0 = interpolate(string00 = string0, string10 = string1);
    };
  }

  function styleRemove(name) {
    return function() {
      this.style.removeProperty(name);
    };
  }

  function styleConstant(name, interpolate, value1) {
    var string00,
        string1 = value1 + "",
        interpolate0;
    return function() {
      var string0 = styleValue(this, name);
      return string0 === string1 ? null
          : string0 === string00 ? interpolate0
          : interpolate0 = interpolate(string00 = string0, value1);
    };
  }

  function styleFunction(name, interpolate, value) {
    var string00,
        string10,
        interpolate0;
    return function() {
      var string0 = styleValue(this, name),
          value1 = value(this),
          string1 = value1 + "";
      if (value1 == null) string1 = value1 = (this.style.removeProperty(name), styleValue(this, name));
      return string0 === string1 ? null
          : string0 === string00 && string1 === string10 ? interpolate0
          : (string10 = string1, interpolate0 = interpolate(string00 = string0, value1));
    };
  }

  function styleMaybeRemove(id, name) {
    var on0, on1, listener0, key = "style." + name, event = "end." + key, remove;
    return function() {
      var schedule = set$1(this, id),
          on = schedule.on,
          listener = schedule.value[key] == null ? remove || (remove = styleRemove(name)) : undefined;

      // If this node shared a dispatch with the previous node,
      // just assign the updated shared dispatch and we’re done!
      // Otherwise, copy-on-write.
      if (on !== on0 || listener0 !== listener) (on1 = (on0 = on).copy()).on(event, listener0 = listener);

      schedule.on = on1;
    };
  }

  function transition_style(name, value, priority) {
    var i = (name += "") === "transform" ? interpolateTransformCss : interpolate$1;
    return value == null ? this
        .styleTween(name, styleNull(name, i))
        .on("end.style." + name, styleRemove(name))
      : typeof value === "function" ? this
        .styleTween(name, styleFunction(name, i, tweenValue(this, "style." + name, value)))
        .each(styleMaybeRemove(this._id, name))
      : this
        .styleTween(name, styleConstant(name, i, value), priority)
        .on("end.style." + name, null);
  }

  function styleInterpolate(name, i, priority) {
    return function(t) {
      this.style.setProperty(name, i.call(this, t), priority);
    };
  }

  function styleTween(name, value, priority) {
    var t, i0;
    function tween() {
      var i = value.apply(this, arguments);
      if (i !== i0) t = (i0 = i) && styleInterpolate(name, i, priority);
      return t;
    }
    tween._value = value;
    return tween;
  }

  function transition_styleTween(name, value, priority) {
    var key = "style." + (name += "");
    if (arguments.length < 2) return (key = this.tween(key)) && key._value;
    if (value == null) return this.tween(key, null);
    if (typeof value !== "function") throw new Error;
    return this.tween(key, styleTween(name, value, priority == null ? "" : priority));
  }

  function textConstant(value) {
    return function() {
      this.textContent = value;
    };
  }

  function textFunction(value) {
    return function() {
      var value1 = value(this);
      this.textContent = value1 == null ? "" : value1;
    };
  }

  function transition_text(value) {
    return this.tween("text", typeof value === "function"
        ? textFunction(tweenValue(this, "text", value))
        : textConstant(value == null ? "" : value + ""));
  }

  function textInterpolate(i) {
    return function(t) {
      this.textContent = i.call(this, t);
    };
  }

  function textTween(value) {
    var t0, i0;
    function tween() {
      var i = value.apply(this, arguments);
      if (i !== i0) t0 = (i0 = i) && textInterpolate(i);
      return t0;
    }
    tween._value = value;
    return tween;
  }

  function transition_textTween(value) {
    var key = "text";
    if (arguments.length < 1) return (key = this.tween(key)) && key._value;
    if (value == null) return this.tween(key, null);
    if (typeof value !== "function") throw new Error;
    return this.tween(key, textTween(value));
  }

  function transition_transition() {
    var name = this._name,
        id0 = this._id,
        id1 = newId();

    for (var groups = this._groups, m = groups.length, j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, node, i = 0; i < n; ++i) {
        if (node = group[i]) {
          var inherit = get$1(node, id0);
          schedule(node, name, id1, i, group, {
            time: inherit.time + inherit.delay + inherit.duration,
            delay: 0,
            duration: inherit.duration,
            ease: inherit.ease
          });
        }
      }
    }

    return new Transition(groups, this._parents, name, id1);
  }

  function transition_end() {
    var on0, on1, that = this, id = that._id, size = that.size();
    return new Promise(function(resolve, reject) {
      var cancel = {value: reject},
          end = {value: function() { if (--size === 0) resolve(); }};

      that.each(function() {
        var schedule = set$1(this, id),
            on = schedule.on;

        // If this node shared a dispatch with the previous node,
        // just assign the updated shared dispatch and we’re done!
        // Otherwise, copy-on-write.
        if (on !== on0) {
          on1 = (on0 = on).copy();
          on1._.cancel.push(cancel);
          on1._.interrupt.push(cancel);
          on1._.end.push(end);
        }

        schedule.on = on1;
      });

      // The selection was empty, resolve end immediately
      if (size === 0) resolve();
    });
  }

  var id = 0;

  function Transition(groups, parents, name, id) {
    this._groups = groups;
    this._parents = parents;
    this._name = name;
    this._id = id;
  }

  function newId() {
    return ++id;
  }

  var selection_prototype = selection.prototype;

  Transition.prototype = {
    constructor: Transition,
    select: transition_select,
    selectAll: transition_selectAll,
    selectChild: selection_prototype.selectChild,
    selectChildren: selection_prototype.selectChildren,
    filter: transition_filter,
    merge: transition_merge,
    selection: transition_selection,
    transition: transition_transition,
    call: selection_prototype.call,
    nodes: selection_prototype.nodes,
    node: selection_prototype.node,
    size: selection_prototype.size,
    empty: selection_prototype.empty,
    each: selection_prototype.each,
    on: transition_on,
    attr: transition_attr,
    attrTween: transition_attrTween,
    style: transition_style,
    styleTween: transition_styleTween,
    text: transition_text,
    textTween: transition_textTween,
    remove: transition_remove,
    tween: transition_tween,
    delay: transition_delay,
    duration: transition_duration,
    ease: transition_ease,
    easeVarying: transition_easeVarying,
    end: transition_end,
    [Symbol.iterator]: selection_prototype[Symbol.iterator]
  };

  function cubicInOut(t) {
    return ((t *= 2) <= 1 ? t * t * t : (t -= 2) * t * t + 2) / 2;
  }

  var defaultTiming = {
    time: null, // Set on use.
    delay: 0,
    duration: 250,
    ease: cubicInOut
  };

  function inherit(node, id) {
    var timing;
    while (!(timing = node.__transition) || !(timing = timing[id])) {
      if (!(node = node.parentNode)) {
        throw new Error(`transition ${id} not found`);
      }
    }
    return timing;
  }

  function selection_transition(name) {
    var id,
        timing;

    if (name instanceof Transition) {
      id = name._id, name = name._name;
    } else {
      id = newId(), (timing = defaultTiming).time = now(), name = name == null ? null : name + "";
    }

    for (var groups = this._groups, m = groups.length, j = 0; j < m; ++j) {
      for (var group = groups[j], n = group.length, node, i = 0; i < n; ++i) {
        if (node = group[i]) {
          schedule(node, name, id, i, group, timing || inherit(node, id));
        }
      }
    }

    return new Transition(groups, this._parents, name, id);
  }

  selection.prototype.interrupt = selection_interrupt;
  selection.prototype.transition = selection_transition;

  function colors(specifier) {
    var n = specifier.length / 6 | 0, colors = new Array(n), i = 0;
    while (i < n) colors[i] = "#" + specifier.slice(i * 6, ++i * 6);
    return colors;
  }

  var schemeTableau10 = colors("4e79a7f28e2ce1575976b7b259a14fedc949af7aa1ff9da79c755fbab0ab");

  var ramp = scheme => rgbBasis(scheme[scheme.length - 1]);

  var scheme = new Array(3).concat(
    "deebf79ecae13182bd",
    "eff3ffbdd7e76baed62171b5",
    "eff3ffbdd7e76baed63182bd08519c",
    "eff3ffc6dbef9ecae16baed63182bd08519c",
    "eff3ffc6dbef9ecae16baed64292c62171b5084594",
    "f7fbffdeebf7c6dbef9ecae16baed64292c62171b5084594",
    "f7fbffdeebf7c6dbef9ecae16baed64292c62171b508519c08306b"
  ).map(colors);

  var interpolateBlues = ramp(scheme);

  function Transform(k, x, y) {
    this.k = k;
    this.x = x;
    this.y = y;
  }

  Transform.prototype = {
    constructor: Transform,
    scale: function(k) {
      return k === 1 ? this : new Transform(this.k * k, this.x, this.y);
    },
    translate: function(x, y) {
      return x === 0 & y === 0 ? this : new Transform(this.k, this.x + this.k * x, this.y + this.k * y);
    },
    apply: function(point) {
      return [point[0] * this.k + this.x, point[1] * this.k + this.y];
    },
    applyX: function(x) {
      return x * this.k + this.x;
    },
    applyY: function(y) {
      return y * this.k + this.y;
    },
    invert: function(location) {
      return [(location[0] - this.x) / this.k, (location[1] - this.y) / this.k];
    },
    invertX: function(x) {
      return (x - this.x) / this.k;
    },
    invertY: function(y) {
      return (y - this.y) / this.k;
    },
    rescaleX: function(x) {
      return x.copy().domain(x.range().map(this.invertX, this).map(x.invert, x));
    },
    rescaleY: function(y) {
      return y.copy().domain(y.range().map(this.invertY, this).map(y.invert, y));
    },
    toString: function() {
      return "translate(" + this.x + "," + this.y + ") scale(" + this.k + ")";
    }
  };

  Transform.prototype;

  function identity(x) {
    return x;
  }

  var top = 1,
      right = 2,
      bottom = 3,
      left = 4,
      epsilon$2 = 1e-6;

  function translateX(x) {
    return "translate(" + x + ",0)";
  }

  function translateY(y) {
    return "translate(0," + y + ")";
  }

  function number$1(scale) {
    return d => +scale(d);
  }

  function center(scale, offset) {
    offset = Math.max(0, scale.bandwidth() - offset * 2) / 2;
    if (scale.round()) offset = Math.round(offset);
    return d => +scale(d) + offset;
  }

  function entering() {
    return !this.__axis;
  }

  function axis(orient, scale) {
    var tickArguments = [],
        tickValues = null,
        tickFormat = null,
        tickSizeInner = 6,
        tickSizeOuter = 6,
        tickPadding = 3,
        offset = typeof window !== "undefined" && window.devicePixelRatio > 1 ? 0 : 0.5,
        k = orient === top || orient === left ? -1 : 1,
        x = orient === left || orient === right ? "x" : "y",
        transform = orient === top || orient === bottom ? translateX : translateY;

    function axis(context) {
      var values = tickValues == null ? (scale.ticks ? scale.ticks.apply(scale, tickArguments) : scale.domain()) : tickValues,
          format = tickFormat == null ? (scale.tickFormat ? scale.tickFormat.apply(scale, tickArguments) : identity) : tickFormat,
          spacing = Math.max(tickSizeInner, 0) + tickPadding,
          range = scale.range(),
          range0 = +range[0] + offset,
          range1 = +range[range.length - 1] + offset,
          position = (scale.bandwidth ? center : number$1)(scale.copy(), offset),
          selection = context.selection ? context.selection() : context,
          path = selection.selectAll(".domain").data([null]),
          tick = selection.selectAll(".tick").data(values, scale).order(),
          tickExit = tick.exit(),
          tickEnter = tick.enter().append("g").attr("class", "tick"),
          line = tick.select("line"),
          text = tick.select("text");

      path = path.merge(path.enter().insert("path", ".tick")
          .attr("class", "domain")
          .attr("stroke", "currentColor"));

      tick = tick.merge(tickEnter);

      line = line.merge(tickEnter.append("line")
          .attr("stroke", "currentColor")
          .attr(x + "2", k * tickSizeInner));

      text = text.merge(tickEnter.append("text")
          .attr("fill", "currentColor")
          .attr(x, k * spacing)
          .attr("dy", orient === top ? "0em" : orient === bottom ? "0.71em" : "0.32em"));

      if (context !== selection) {
        path = path.transition(context);
        tick = tick.transition(context);
        line = line.transition(context);
        text = text.transition(context);

        tickExit = tickExit.transition(context)
            .attr("opacity", epsilon$2)
            .attr("transform", function(d) { return isFinite(d = position(d)) ? transform(d + offset) : this.getAttribute("transform"); });

        tickEnter
            .attr("opacity", epsilon$2)
            .attr("transform", function(d) { var p = this.parentNode.__axis; return transform((p && isFinite(p = p(d)) ? p : position(d)) + offset); });
      }

      tickExit.remove();

      path
          .attr("d", orient === left || orient === right
              ? (tickSizeOuter ? "M" + k * tickSizeOuter + "," + range0 + "H" + offset + "V" + range1 + "H" + k * tickSizeOuter : "M" + offset + "," + range0 + "V" + range1)
              : (tickSizeOuter ? "M" + range0 + "," + k * tickSizeOuter + "V" + offset + "H" + range1 + "V" + k * tickSizeOuter : "M" + range0 + "," + offset + "H" + range1));

      tick
          .attr("opacity", 1)
          .attr("transform", function(d) { return transform(position(d) + offset); });

      line
          .attr(x + "2", k * tickSizeInner);

      text
          .attr(x, k * spacing)
          .text(format);

      selection.filter(entering)
          .attr("fill", "none")
          .attr("font-size", 10)
          .attr("font-family", "sans-serif")
          .attr("text-anchor", orient === right ? "start" : orient === left ? "end" : "middle");

      selection
          .each(function() { this.__axis = position; });
    }

    axis.scale = function(_) {
      return arguments.length ? (scale = _, axis) : scale;
    };

    axis.ticks = function() {
      return tickArguments = Array.from(arguments), axis;
    };

    axis.tickArguments = function(_) {
      return arguments.length ? (tickArguments = _ == null ? [] : Array.from(_), axis) : tickArguments.slice();
    };

    axis.tickValues = function(_) {
      return arguments.length ? (tickValues = _ == null ? null : Array.from(_), axis) : tickValues && tickValues.slice();
    };

    axis.tickFormat = function(_) {
      return arguments.length ? (tickFormat = _, axis) : tickFormat;
    };

    axis.tickSize = function(_) {
      return arguments.length ? (tickSizeInner = tickSizeOuter = +_, axis) : tickSizeInner;
    };

    axis.tickSizeInner = function(_) {
      return arguments.length ? (tickSizeInner = +_, axis) : tickSizeInner;
    };

    axis.tickSizeOuter = function(_) {
      return arguments.length ? (tickSizeOuter = +_, axis) : tickSizeOuter;
    };

    axis.tickPadding = function(_) {
      return arguments.length ? (tickPadding = +_, axis) : tickPadding;
    };

    axis.offset = function(_) {
      return arguments.length ? (offset = +_, axis) : offset;
    };

    return axis;
  }

  function axisBottom(scale) {
    return axis(bottom, scale);
  }

  function axisLeft(scale) {
    return axis(left, scale);
  }

  function cubicOut(t) {
    return --t * t * t + 1;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */

  /**
   * Escape a string for safe interpolation into a tooltip's innerHTML.
   * Tooltip bodies built from user-data strings (place names, given
   * names, surname tokens) must never trust the input — a hand-edited
   * GEDCOM or marketplace catalog can contain `<script>` or stray
   * quotes that would break the DOM or open an XSS surface.
   *
   * @param {string} value Raw text from a data source.
   *
   * @returns {string} HTML-safe representation, ready to drop into innerHTML.
   */
  function escapeHtml(value) {
      return String(value)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#39;");
  }

  /**
   * Build a follow-cursor chart tooltip that lives on `document.body`
   * and uses `position: fixed`. Sharing a single body-level element
   * across every chart on the page keeps the DOM lean — only one
   * chart can be hovered at a time, so race-free reuse is safe.
   *
   * The CSS for `.wt-chart-tooltip` (base styling) and the
   * `__stat`/`__meta` modifier classes is the consumer's
   * responsibility — chart-lib ships markup hooks but no opinionated
   * stylesheet here.
   *
   * Show/move/hide clamp the tooltip to the viewport edges and
   * flip above-cursor / left-of-cursor when the preferred placement
   * would overflow.
   *
   * @returns {{
   *   element: HTMLDivElement,
   *   show:    (event: MouseEvent | {clientX: number, clientY: number}, html: string) => void,
   *   move:    (event: MouseEvent | {clientX: number, clientY: number}) => void,
   *   hide:    () => void
   * }}
   */
  function createChartTooltip() {
      let element = document.body.querySelector(":scope > .wt-chart-tooltip");

      if (element === null) {
          element = document.createElement("div");
          element.className = "wt-chart-tooltip";
          document.body.appendChild(element);
      }

      const move = (event) => {
          const tooltipRect = element.getBoundingClientRect();
          const margin = 8;
          const viewportW = window.innerWidth;
          const viewportH = window.innerHeight;

          // Horizontal: prefer right of the cursor, flip left when the
          // tooltip would overflow the viewport's right edge.
          let left = event.clientX + 14;
          if (left + tooltipRect.width + margin > viewportW) {
              left = event.clientX - tooltipRect.width - 14;
          }
          if (left < margin) {
              left = margin;
          }

          // Vertical: prefer below the cursor, flip above when the
          // tooltip would overflow the viewport's bottom edge.
          let top = event.clientY + 14;
          if (top + tooltipRect.height + margin > viewportH) {
              top = event.clientY - tooltipRect.height - 14;
          }
          if (top < margin) {
              top = margin;
          }

          element.style.left = `${left}px`;
          element.style.top = `${top}px`;
      };

      const show = (event, html) => {
          element.innerHTML = html;
          element.classList.add("is-visible");
          move(event);
      };

      const hide = () => {
          element.classList.remove("is-visible");
      };

      return { element, show, move, hide };
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */

  /**
   * Common base class for chart-lib widgets.
   *
   * Subclasses inherit:
   *   - target resolution from id string (with/without leading #) or HTMLElement
   *   - dimensions() with options-over-container-over-defaults precedence
   *   - renderEmptyState() helper that keeps the target free of stale empty-state nodes
   *
   * Dimension precedence: option (finite, > 0) → container clientSize (> 0) → caller default.
   * renderEmptyState() removes any prior direct-child `.chart-empty-state` before appending,
   * so subclass `draw([])` calls are idempotent with respect to the placeholder.
   * Subclasses remain responsible for clearing their own chart output between draws.
   *
   * Targets must be HTMLElement; SVG containers are not supported because the
   * placeholder is an HTML <div>. Widgets that render SVG should target an HTML
   * wrapper (`<div>`), not the `<svg>` root.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class BaseWidget {
      /**
       * @param {string|HTMLElement} target  DOM id (with or without leading #) or HTMLElement.
       * @param {object}             [options]  Widget-specific options. See subclasses.
       */
      constructor(target, options) {
          this.target = this._resolveTarget(target);
          this.options = { ...(options ?? {}) };
          this._selectionCallback = null;
          this._currentSelection = null;
      }

      /**
       * Register a callback that fires every time the user clicks a
       * widget element that opts in to selection. The widget calls
       * `cb({source, predicate})` on click, where `predicate: null`
       * signals a cleared selection (the user clicked the same
       * element twice). `source` is the widget's identity from
       * `options.source` (or empty string when unset) so the
       * dashboard-bus can disambiguate multi-widget pages.
       *
       * @param {(payload: {source: string, predicate: object|null}) => void} callback
       * @returns {this}
       */
      onSelectionChanged(callback) {
          this._selectionCallback = typeof callback === "function" ? callback : null;
          return this;
      }

      /**
       * Internal helper used by selection-enabled subclasses to
       * surface a click. Toggles the predicate against the previous
       * selection so re-clicking the same predicate clears.
       *
       * @param {object|null} predicate  Widget-specific shape (e.g. `{slice: 'Male'}`)
       * @returns {{predicate: object|null}}  The post-toggle selection state
       */
      _emitSelection(predicate) {
          const next = this._samePredicate(this._currentSelection, predicate) ? null : predicate;
          this._currentSelection = next;
          if (this._selectionCallback !== null) {
              this._selectionCallback({
                  source: typeof this.options.source === "string" ? this.options.source : "",
                  predicate: next,
              });
          }
          return { predicate: next };
      }

      /**
       * Apply an externally-set selection (typically broadcast by a
       * dashboard bus from a sibling widget). Subclasses override
       * `_applySelection` to update their visual highlight state; the
       * base implementation only tracks the predicate so subsequent
       * toggles still work.
       *
       * Predicate shape is widget-specific and intentionally opaque
       * to the bus — a widget that doesn't recognise the shape just
       * leaves its highlight untouched, which is the correct default
       * when sibling widgets emit a dimension the receiver doesn't
       * carry (e.g. surname predicate hits a century-keyed donut).
       *
       * @param {object|null} predicate  `null` clears the highlight.
       * @returns {this}
       */
      setSelection(predicate) {
          this._currentSelection = predicate;
          this._applySelection(predicate);
          return this;
      }

      /**
       * Subclass-overridable hook called by `setSelection`. Default
       * no-op so widgets that don't carry a sensible visual highlight
       * (or haven't migrated yet) simply ignore foreign selections.
       *
       * @param {object|null} _predicate
       * @returns {void}
       */
      _applySelection(_predicate) {
          // No-op default.
      }

      /**
       * Shallow equality test for predicate toggling — same keys
       * with same primitive values count as the same selection.
       *
       * @param {object|null} a
       * @param {object|null} b
       * @returns {boolean}
       */
      _samePredicate(a, b) {
          if (a === null || b === null) {
              return false;
          }
          const aKeys = Object.keys(a);
          const bKeys = Object.keys(b);
          if (aKeys.length !== bKeys.length) {
              return false;
          }
          return aKeys.every((key) => a[key] === b[key]);
      }

      /**
       * @param {string|HTMLElement} target
       * @returns {HTMLElement}
       */
      _resolveTarget(target) {
          if (target instanceof HTMLElement) {
              return target;
          }
          if (typeof target !== "string" || target.length === 0) {
              throw new Error(
                  `${this.constructor.name}: target must be an HTMLElement or a non-empty id string`,
              );
          }
          const id = target.startsWith("#") ? target.slice(1) : target;
          const el = document.getElementById(id);
          if (el === null) {
              throw new Error(`${this.constructor.name}: target not found for "${target}"`);
          }
          return el;
      }

      /**
       * Resolve effective width / height. Option wins if finite-positive,
       * otherwise container clientSize, otherwise the caller-supplied default.
       *
       * @param {{width: number, height: number}} defaults
       * @returns {{width: number, height: number}}
       */
      dimensions(defaults) {
          return {
              width: pickDimension(this.options.width, this.target.clientWidth, defaults.width),
              height: pickDimension(this.options.height, this.target.clientHeight, defaults.height),
          };
      }

      /**
       * Replace any prior empty-state placeholder under target with a fresh one.
       *
       * @param {string} message  Human-readable message rendered as text (no HTML)
       * @returns {HTMLElement}
       */
      renderEmptyState(message) {
          const text = coerceMessage(message);
          const el = document.createElement("div");
          el.className = "chart-empty-state";
          el.textContent = text;
          for (const stale of this.target.querySelectorAll(":scope > .chart-empty-state")) {
              stale.remove();
          }
          this.target.appendChild(el);
          return el;
      }
  }

  /**
   * @param {unknown} optionValue
   * @param {number}  containerValue
   * @param {number}  defaultValue
   * @returns {number}
   */
  function pickDimension(optionValue, containerValue, defaultValue) {
      if (typeof optionValue === "number" && Number.isFinite(optionValue) && optionValue > 0) {
          return optionValue;
      }
      if (typeof containerValue === "number" && containerValue > 0) {
          return containerValue;
      }
      return defaultValue;
  }

  /**
   * Coerce any value to a placeholder text string. Falls back to empty string
   * if a custom toString throws (e.g. proxies with throwing traps).
   *
   * @param {unknown} message
   * @returns {string}
   */
  function coerceMessage(message) {
      if (message === null || message === undefined) {
          return "";
      }
      try {
          return String(message);
      } catch {
          return "";
      }
  }

  var noop = {value: () => {}};

  function dispatch() {
    for (var i = 0, n = arguments.length, _ = {}, t; i < n; ++i) {
      if (!(t = arguments[i] + "") || (t in _) || /[\s.]/.test(t)) throw new Error("illegal type: " + t);
      _[t] = [];
    }
    return new Dispatch(_);
  }

  function Dispatch(_) {
    this._ = _;
  }

  function parseTypenames(typenames, types) {
    return typenames.trim().split(/^|\s+/).map(function(t) {
      var name = "", i = t.indexOf(".");
      if (i >= 0) name = t.slice(i + 1), t = t.slice(0, i);
      if (t && !types.hasOwnProperty(t)) throw new Error("unknown type: " + t);
      return {type: t, name: name};
    });
  }

  Dispatch.prototype = dispatch.prototype = {
    constructor: Dispatch,
    on: function(typename, callback) {
      var _ = this._,
          T = parseTypenames(typename + "", _),
          t,
          i = -1,
          n = T.length;

      // If no callback was specified, return the callback of the given type and name.
      if (arguments.length < 2) {
        while (++i < n) if ((t = (typename = T[i]).type) && (t = get(_[t], typename.name))) return t;
        return;
      }

      // If a type was specified, set the callback for the given type and name.
      // Otherwise, if a null callback was specified, remove callbacks of the given name.
      if (callback != null && typeof callback !== "function") throw new Error("invalid callback: " + callback);
      while (++i < n) {
        if (t = (typename = T[i]).type) _[t] = set(_[t], typename.name, callback);
        else if (callback == null) for (t in _) _[t] = set(_[t], typename.name, null);
      }

      return this;
    },
    copy: function() {
      var copy = {}, _ = this._;
      for (var t in _) copy[t] = _[t].slice();
      return new Dispatch(copy);
    },
    call: function(type, that) {
      if ((n = arguments.length - 2) > 0) for (var args = new Array(n), i = 0, n, t; i < n; ++i) args[i] = arguments[i + 2];
      if (!this._.hasOwnProperty(type)) throw new Error("unknown type: " + type);
      for (t = this._[type], i = 0, n = t.length; i < n; ++i) t[i].value.apply(that, args);
    },
    apply: function(type, that, args) {
      if (!this._.hasOwnProperty(type)) throw new Error("unknown type: " + type);
      for (var t = this._[type], i = 0, n = t.length; i < n; ++i) t[i].value.apply(that, args);
    }
  };

  function get(type, name) {
    for (var i = 0, n = type.length, c; i < n; ++i) {
      if ((c = type[i]).name === name) {
        return c.value;
      }
    }
  }

  function set(type, name, callback) {
    for (var i = 0, n = type.length; i < n; ++i) {
      if (type[i].name === name) {
        type[i] = noop, type = type.slice(0, i).concat(type.slice(i + 1));
        break;
      }
    }
    if (callback != null) type.push({name: name, value: callback});
    return type;
  }

  // These are typically used in conjunction with noevent to ensure that we can
  // preventDefault on the event.
  const nonpassivecapture = {capture: true, passive: false};

  function noevent$1(event) {
    event.preventDefault();
    event.stopImmediatePropagation();
  }

  function dragDisable(view) {
    var root = view.document.documentElement,
        selection = select(view).on("dragstart.drag", noevent$1, nonpassivecapture);
    if ("onselectstart" in root) {
      selection.on("selectstart.drag", noevent$1, nonpassivecapture);
    } else {
      root.__noselect = root.style.MozUserSelect;
      root.style.MozUserSelect = "none";
    }
  }

  function yesdrag(view, noclick) {
    var root = view.document.documentElement,
        selection = select(view).on("dragstart.drag", null);
    if (noclick) {
      selection.on("click.drag", noevent$1, nonpassivecapture);
      setTimeout(function() { selection.on("click.drag", null); }, 0);
    }
    if ("onselectstart" in root) {
      selection.on("selectstart.drag", null);
    } else {
      root.style.MozUserSelect = root.__noselect;
      delete root.__noselect;
    }
  }

  function define(constructor, factory, prototype) {
    constructor.prototype = factory.prototype = prototype;
    prototype.constructor = constructor;
  }

  function extend(parent, definition) {
    var prototype = Object.create(parent.prototype);
    for (var key in definition) prototype[key] = definition[key];
    return prototype;
  }

  function Color() {}

  var darker = 0.7;
  var brighter = 1 / darker;

  var reI = "\\s*([+-]?\\d+)\\s*",
      reN = "\\s*([+-]?(?:\\d*\\.)?\\d+(?:[eE][+-]?\\d+)?)\\s*",
      reP = "\\s*([+-]?(?:\\d*\\.)?\\d+(?:[eE][+-]?\\d+)?)%\\s*",
      reHex = /^#([0-9a-f]{3,8})$/,
      reRgbInteger = new RegExp(`^rgb\\(${reI},${reI},${reI}\\)$`),
      reRgbPercent = new RegExp(`^rgb\\(${reP},${reP},${reP}\\)$`),
      reRgbaInteger = new RegExp(`^rgba\\(${reI},${reI},${reI},${reN}\\)$`),
      reRgbaPercent = new RegExp(`^rgba\\(${reP},${reP},${reP},${reN}\\)$`),
      reHslPercent = new RegExp(`^hsl\\(${reN},${reP},${reP}\\)$`),
      reHslaPercent = new RegExp(`^hsla\\(${reN},${reP},${reP},${reN}\\)$`);

  var named = {
    aliceblue: 0xf0f8ff,
    antiquewhite: 0xfaebd7,
    aqua: 0x00ffff,
    aquamarine: 0x7fffd4,
    azure: 0xf0ffff,
    beige: 0xf5f5dc,
    bisque: 0xffe4c4,
    black: 0x000000,
    blanchedalmond: 0xffebcd,
    blue: 0x0000ff,
    blueviolet: 0x8a2be2,
    brown: 0xa52a2a,
    burlywood: 0xdeb887,
    cadetblue: 0x5f9ea0,
    chartreuse: 0x7fff00,
    chocolate: 0xd2691e,
    coral: 0xff7f50,
    cornflowerblue: 0x6495ed,
    cornsilk: 0xfff8dc,
    crimson: 0xdc143c,
    cyan: 0x00ffff,
    darkblue: 0x00008b,
    darkcyan: 0x008b8b,
    darkgoldenrod: 0xb8860b,
    darkgray: 0xa9a9a9,
    darkgreen: 0x006400,
    darkgrey: 0xa9a9a9,
    darkkhaki: 0xbdb76b,
    darkmagenta: 0x8b008b,
    darkolivegreen: 0x556b2f,
    darkorange: 0xff8c00,
    darkorchid: 0x9932cc,
    darkred: 0x8b0000,
    darksalmon: 0xe9967a,
    darkseagreen: 0x8fbc8f,
    darkslateblue: 0x483d8b,
    darkslategray: 0x2f4f4f,
    darkslategrey: 0x2f4f4f,
    darkturquoise: 0x00ced1,
    darkviolet: 0x9400d3,
    deeppink: 0xff1493,
    deepskyblue: 0x00bfff,
    dimgray: 0x696969,
    dimgrey: 0x696969,
    dodgerblue: 0x1e90ff,
    firebrick: 0xb22222,
    floralwhite: 0xfffaf0,
    forestgreen: 0x228b22,
    fuchsia: 0xff00ff,
    gainsboro: 0xdcdcdc,
    ghostwhite: 0xf8f8ff,
    gold: 0xffd700,
    goldenrod: 0xdaa520,
    gray: 0x808080,
    green: 0x008000,
    greenyellow: 0xadff2f,
    grey: 0x808080,
    honeydew: 0xf0fff0,
    hotpink: 0xff69b4,
    indianred: 0xcd5c5c,
    indigo: 0x4b0082,
    ivory: 0xfffff0,
    khaki: 0xf0e68c,
    lavender: 0xe6e6fa,
    lavenderblush: 0xfff0f5,
    lawngreen: 0x7cfc00,
    lemonchiffon: 0xfffacd,
    lightblue: 0xadd8e6,
    lightcoral: 0xf08080,
    lightcyan: 0xe0ffff,
    lightgoldenrodyellow: 0xfafad2,
    lightgray: 0xd3d3d3,
    lightgreen: 0x90ee90,
    lightgrey: 0xd3d3d3,
    lightpink: 0xffb6c1,
    lightsalmon: 0xffa07a,
    lightseagreen: 0x20b2aa,
    lightskyblue: 0x87cefa,
    lightslategray: 0x778899,
    lightslategrey: 0x778899,
    lightsteelblue: 0xb0c4de,
    lightyellow: 0xffffe0,
    lime: 0x00ff00,
    limegreen: 0x32cd32,
    linen: 0xfaf0e6,
    magenta: 0xff00ff,
    maroon: 0x800000,
    mediumaquamarine: 0x66cdaa,
    mediumblue: 0x0000cd,
    mediumorchid: 0xba55d3,
    mediumpurple: 0x9370db,
    mediumseagreen: 0x3cb371,
    mediumslateblue: 0x7b68ee,
    mediumspringgreen: 0x00fa9a,
    mediumturquoise: 0x48d1cc,
    mediumvioletred: 0xc71585,
    midnightblue: 0x191970,
    mintcream: 0xf5fffa,
    mistyrose: 0xffe4e1,
    moccasin: 0xffe4b5,
    navajowhite: 0xffdead,
    navy: 0x000080,
    oldlace: 0xfdf5e6,
    olive: 0x808000,
    olivedrab: 0x6b8e23,
    orange: 0xffa500,
    orangered: 0xff4500,
    orchid: 0xda70d6,
    palegoldenrod: 0xeee8aa,
    palegreen: 0x98fb98,
    paleturquoise: 0xafeeee,
    palevioletred: 0xdb7093,
    papayawhip: 0xffefd5,
    peachpuff: 0xffdab9,
    peru: 0xcd853f,
    pink: 0xffc0cb,
    plum: 0xdda0dd,
    powderblue: 0xb0e0e6,
    purple: 0x800080,
    rebeccapurple: 0x663399,
    red: 0xff0000,
    rosybrown: 0xbc8f8f,
    royalblue: 0x4169e1,
    saddlebrown: 0x8b4513,
    salmon: 0xfa8072,
    sandybrown: 0xf4a460,
    seagreen: 0x2e8b57,
    seashell: 0xfff5ee,
    sienna: 0xa0522d,
    silver: 0xc0c0c0,
    skyblue: 0x87ceeb,
    slateblue: 0x6a5acd,
    slategray: 0x708090,
    slategrey: 0x708090,
    snow: 0xfffafa,
    springgreen: 0x00ff7f,
    steelblue: 0x4682b4,
    tan: 0xd2b48c,
    teal: 0x008080,
    thistle: 0xd8bfd8,
    tomato: 0xff6347,
    turquoise: 0x40e0d0,
    violet: 0xee82ee,
    wheat: 0xf5deb3,
    white: 0xffffff,
    whitesmoke: 0xf5f5f5,
    yellow: 0xffff00,
    yellowgreen: 0x9acd32
  };

  define(Color, color, {
    copy(channels) {
      return Object.assign(new this.constructor, this, channels);
    },
    displayable() {
      return this.rgb().displayable();
    },
    hex: color_formatHex, // Deprecated! Use color.formatHex.
    formatHex: color_formatHex,
    formatHex8: color_formatHex8,
    formatHsl: color_formatHsl,
    formatRgb: color_formatRgb,
    toString: color_formatRgb
  });

  function color_formatHex() {
    return this.rgb().formatHex();
  }

  function color_formatHex8() {
    return this.rgb().formatHex8();
  }

  function color_formatHsl() {
    return hslConvert(this).formatHsl();
  }

  function color_formatRgb() {
    return this.rgb().formatRgb();
  }

  function color(format) {
    var m, l;
    format = (format + "").trim().toLowerCase();
    return (m = reHex.exec(format)) ? (l = m[1].length, m = parseInt(m[1], 16), l === 6 ? rgbn(m) // #ff0000
        : l === 3 ? new Rgb((m >> 8 & 0xf) | (m >> 4 & 0xf0), (m >> 4 & 0xf) | (m & 0xf0), ((m & 0xf) << 4) | (m & 0xf), 1) // #f00
        : l === 8 ? rgba(m >> 24 & 0xff, m >> 16 & 0xff, m >> 8 & 0xff, (m & 0xff) / 0xff) // #ff000000
        : l === 4 ? rgba((m >> 12 & 0xf) | (m >> 8 & 0xf0), (m >> 8 & 0xf) | (m >> 4 & 0xf0), (m >> 4 & 0xf) | (m & 0xf0), (((m & 0xf) << 4) | (m & 0xf)) / 0xff) // #f000
        : null) // invalid hex
        : (m = reRgbInteger.exec(format)) ? new Rgb(m[1], m[2], m[3], 1) // rgb(255, 0, 0)
        : (m = reRgbPercent.exec(format)) ? new Rgb(m[1] * 255 / 100, m[2] * 255 / 100, m[3] * 255 / 100, 1) // rgb(100%, 0%, 0%)
        : (m = reRgbaInteger.exec(format)) ? rgba(m[1], m[2], m[3], m[4]) // rgba(255, 0, 0, 1)
        : (m = reRgbaPercent.exec(format)) ? rgba(m[1] * 255 / 100, m[2] * 255 / 100, m[3] * 255 / 100, m[4]) // rgb(100%, 0%, 0%, 1)
        : (m = reHslPercent.exec(format)) ? hsla(m[1], m[2] / 100, m[3] / 100, 1) // hsl(120, 50%, 50%)
        : (m = reHslaPercent.exec(format)) ? hsla(m[1], m[2] / 100, m[3] / 100, m[4]) // hsla(120, 50%, 50%, 1)
        : named.hasOwnProperty(format) ? rgbn(named[format]) // eslint-disable-line no-prototype-builtins
        : format === "transparent" ? new Rgb(NaN, NaN, NaN, 0)
        : null;
  }

  function rgbn(n) {
    return new Rgb(n >> 16 & 0xff, n >> 8 & 0xff, n & 0xff, 1);
  }

  function rgba(r, g, b, a) {
    if (a <= 0) r = g = b = NaN;
    return new Rgb(r, g, b, a);
  }

  function rgbConvert(o) {
    if (!(o instanceof Color)) o = color(o);
    if (!o) return new Rgb;
    o = o.rgb();
    return new Rgb(o.r, o.g, o.b, o.opacity);
  }

  function rgb(r, g, b, opacity) {
    return arguments.length === 1 ? rgbConvert(r) : new Rgb(r, g, b, opacity == null ? 1 : opacity);
  }

  function Rgb(r, g, b, opacity) {
    this.r = +r;
    this.g = +g;
    this.b = +b;
    this.opacity = +opacity;
  }

  define(Rgb, rgb, extend(Color, {
    brighter(k) {
      k = k == null ? brighter : Math.pow(brighter, k);
      return new Rgb(this.r * k, this.g * k, this.b * k, this.opacity);
    },
    darker(k) {
      k = k == null ? darker : Math.pow(darker, k);
      return new Rgb(this.r * k, this.g * k, this.b * k, this.opacity);
    },
    rgb() {
      return this;
    },
    clamp() {
      return new Rgb(clampi(this.r), clampi(this.g), clampi(this.b), clampa(this.opacity));
    },
    displayable() {
      return (-0.5 <= this.r && this.r < 255.5)
          && (-0.5 <= this.g && this.g < 255.5)
          && (-0.5 <= this.b && this.b < 255.5)
          && (0 <= this.opacity && this.opacity <= 1);
    },
    hex: rgb_formatHex, // Deprecated! Use color.formatHex.
    formatHex: rgb_formatHex,
    formatHex8: rgb_formatHex8,
    formatRgb: rgb_formatRgb,
    toString: rgb_formatRgb
  }));

  function rgb_formatHex() {
    return `#${hex(this.r)}${hex(this.g)}${hex(this.b)}`;
  }

  function rgb_formatHex8() {
    return `#${hex(this.r)}${hex(this.g)}${hex(this.b)}${hex((isNaN(this.opacity) ? 1 : this.opacity) * 255)}`;
  }

  function rgb_formatRgb() {
    const a = clampa(this.opacity);
    return `${a === 1 ? "rgb(" : "rgba("}${clampi(this.r)}, ${clampi(this.g)}, ${clampi(this.b)}${a === 1 ? ")" : `, ${a})`}`;
  }

  function clampa(opacity) {
    return isNaN(opacity) ? 1 : Math.max(0, Math.min(1, opacity));
  }

  function clampi(value) {
    return Math.max(0, Math.min(255, Math.round(value) || 0));
  }

  function hex(value) {
    value = clampi(value);
    return (value < 16 ? "0" : "") + value.toString(16);
  }

  function hsla(h, s, l, a) {
    if (a <= 0) h = s = l = NaN;
    else if (l <= 0 || l >= 1) h = s = NaN;
    else if (s <= 0) h = NaN;
    return new Hsl(h, s, l, a);
  }

  function hslConvert(o) {
    if (o instanceof Hsl) return new Hsl(o.h, o.s, o.l, o.opacity);
    if (!(o instanceof Color)) o = color(o);
    if (!o) return new Hsl;
    if (o instanceof Hsl) return o;
    o = o.rgb();
    var r = o.r / 255,
        g = o.g / 255,
        b = o.b / 255,
        min = Math.min(r, g, b),
        max = Math.max(r, g, b),
        h = NaN,
        s = max - min,
        l = (max + min) / 2;
    if (s) {
      if (r === max) h = (g - b) / s + (g < b) * 6;
      else if (g === max) h = (b - r) / s + 2;
      else h = (r - g) / s + 4;
      s /= l < 0.5 ? max + min : 2 - max - min;
      h *= 60;
    } else {
      s = l > 0 && l < 1 ? 0 : h;
    }
    return new Hsl(h, s, l, o.opacity);
  }

  function hsl(h, s, l, opacity) {
    return arguments.length === 1 ? hslConvert(h) : new Hsl(h, s, l, opacity == null ? 1 : opacity);
  }

  function Hsl(h, s, l, opacity) {
    this.h = +h;
    this.s = +s;
    this.l = +l;
    this.opacity = +opacity;
  }

  define(Hsl, hsl, extend(Color, {
    brighter(k) {
      k = k == null ? brighter : Math.pow(brighter, k);
      return new Hsl(this.h, this.s, this.l * k, this.opacity);
    },
    darker(k) {
      k = k == null ? darker : Math.pow(darker, k);
      return new Hsl(this.h, this.s, this.l * k, this.opacity);
    },
    rgb() {
      var h = this.h % 360 + (this.h < 0) * 360,
          s = isNaN(h) || isNaN(this.s) ? 0 : this.s,
          l = this.l,
          m2 = l + (l < 0.5 ? l : 1 - l) * s,
          m1 = 2 * l - m2;
      return new Rgb(
        hsl2rgb(h >= 240 ? h - 240 : h + 120, m1, m2),
        hsl2rgb(h, m1, m2),
        hsl2rgb(h < 120 ? h + 240 : h - 120, m1, m2),
        this.opacity
      );
    },
    clamp() {
      return new Hsl(clamph(this.h), clampt(this.s), clampt(this.l), clampa(this.opacity));
    },
    displayable() {
      return (0 <= this.s && this.s <= 1 || isNaN(this.s))
          && (0 <= this.l && this.l <= 1)
          && (0 <= this.opacity && this.opacity <= 1);
    },
    formatHsl() {
      const a = clampa(this.opacity);
      return `${a === 1 ? "hsl(" : "hsla("}${clamph(this.h)}, ${clampt(this.s) * 100}%, ${clampt(this.l) * 100}%${a === 1 ? ")" : `, ${a})`}`;
    }
  }));

  function clamph(value) {
    value = (value || 0) % 360;
    return value < 0 ? value + 360 : value;
  }

  function clampt(value) {
    return Math.max(0, Math.min(1, value || 0));
  }

  /* From FvD 13.37, CSS Color Module Level 3 */
  function hsl2rgb(h, m1, m2) {
    return (h < 60 ? m1 + (m2 - m1) * h / 60
        : h < 180 ? m2
        : h < 240 ? m1 + (m2 - m1) * (240 - h) / 60
        : m1) * 255;
  }

  var constant$3 = x => () => x;

  function linear(a, d) {
    return function(t) {
      return a + t * d;
    };
  }

  function exponential(a, b, y) {
    return a = Math.pow(a, y), b = Math.pow(b, y) - a, y = 1 / y, function(t) {
      return Math.pow(a + t * b, y);
    };
  }

  function gamma(y) {
    return (y = +y) === 1 ? nogamma : function(a, b) {
      return b - a ? exponential(a, b, y) : constant$3(isNaN(a) ? b : a);
    };
  }

  function nogamma(a, b) {
    var d = b - a;
    return d ? linear(a, d) : constant$3(isNaN(a) ? b : a);
  }

  var interpolateRgb = (function rgbGamma(y) {
    var color = gamma(y);

    function rgb$1(start, end) {
      var r = color((start = rgb(start)).r, (end = rgb(end)).r),
          g = color(start.g, end.g),
          b = color(start.b, end.b),
          opacity = nogamma(start.opacity, end.opacity);
      return function(t) {
        start.r = r(t);
        start.g = g(t);
        start.b = b(t);
        start.opacity = opacity(t);
        return start + "";
      };
    }

    rgb$1.gamma = rgbGamma;

    return rgb$1;
  })(1);

  function numberArray(a, b) {
    if (!b) b = [];
    var n = a ? Math.min(b.length, a.length) : 0,
        c = b.slice(),
        i;
    return function(t) {
      for (i = 0; i < n; ++i) c[i] = a[i] * (1 - t) + b[i] * t;
      return c;
    };
  }

  function isNumberArray(x) {
    return ArrayBuffer.isView(x) && !(x instanceof DataView);
  }

  function genericArray(a, b) {
    var nb = b ? b.length : 0,
        na = a ? Math.min(nb, a.length) : 0,
        x = new Array(na),
        c = new Array(nb),
        i;

    for (i = 0; i < na; ++i) x[i] = interpolate(a[i], b[i]);
    for (; i < nb; ++i) c[i] = b[i];

    return function(t) {
      for (i = 0; i < na; ++i) c[i] = x[i](t);
      return c;
    };
  }

  function date(a, b) {
    var d = new Date;
    return a = +a, b = +b, function(t) {
      return d.setTime(a * (1 - t) + b * t), d;
    };
  }

  function number(a, b) {
    return a = +a, b = +b, function(t) {
      return a * (1 - t) + b * t;
    };
  }

  function object(a, b) {
    var i = {},
        c = {},
        k;

    if (a === null || typeof a !== "object") a = {};
    if (b === null || typeof b !== "object") b = {};

    for (k in b) {
      if (k in a) {
        i[k] = interpolate(a[k], b[k]);
      } else {
        c[k] = b[k];
      }
    }

    return function(t) {
      for (k in i) c[k] = i[k](t);
      return c;
    };
  }

  var reA = /[-+]?(?:\d+\.?\d*|\.?\d+)(?:[eE][-+]?\d+)?/g,
      reB = new RegExp(reA.source, "g");

  function zero(b) {
    return function() {
      return b;
    };
  }

  function one(b) {
    return function(t) {
      return b(t) + "";
    };
  }

  function string(a, b) {
    var bi = reA.lastIndex = reB.lastIndex = 0, // scan index for next number in b
        am, // current match in a
        bm, // current match in b
        bs, // string preceding current number in b, if any
        i = -1, // index in s
        s = [], // string constants and placeholders
        q = []; // number interpolators

    // Coerce inputs to strings.
    a = a + "", b = b + "";

    // Interpolate pairs of numbers in a & b.
    while ((am = reA.exec(a))
        && (bm = reB.exec(b))) {
      if ((bs = bm.index) > bi) { // a string precedes the next number in b
        bs = b.slice(bi, bs);
        if (s[i]) s[i] += bs; // coalesce with previous string
        else s[++i] = bs;
      }
      if ((am = am[0]) === (bm = bm[0])) { // numbers in a & b match
        if (s[i]) s[i] += bm; // coalesce with previous string
        else s[++i] = bm;
      } else { // interpolate non-matching numbers
        s[++i] = null;
        q.push({i: i, x: number(am, bm)});
      }
      bi = reB.lastIndex;
    }

    // Add remains of b.
    if (bi < b.length) {
      bs = b.slice(bi);
      if (s[i]) s[i] += bs; // coalesce with previous string
      else s[++i] = bs;
    }

    // Special optimization for only a single match.
    // Otherwise, interpolate each of the numbers and rejoin the string.
    return s.length < 2 ? (q[0]
        ? one(q[0].x)
        : zero(b))
        : (b = q.length, function(t) {
            for (var i = 0, o; i < b; ++i) s[(o = q[i]).i] = o.x(t);
            return s.join("");
          });
  }

  function interpolate(a, b) {
    var t = typeof b, c;
    return b == null || t === "boolean" ? constant$3(b)
        : (t === "number" ? number
        : t === "string" ? ((c = color(b)) ? (b = c, interpolateRgb) : string)
        : b instanceof color ? interpolateRgb
        : b instanceof Date ? date
        : isNumberArray(b) ? numberArray
        : Array.isArray(b) ? genericArray
        : typeof b.valueOf !== "function" && typeof b.toString !== "function" || isNaN(b) ? object
        : number)(a, b);
  }

  var constant$2 = x => () => x;

  function BrushEvent(type, {
    sourceEvent,
    target,
    selection,
    mode,
    dispatch
  }) {
    Object.defineProperties(this, {
      type: {value: type, enumerable: true, configurable: true},
      sourceEvent: {value: sourceEvent, enumerable: true, configurable: true},
      target: {value: target, enumerable: true, configurable: true},
      selection: {value: selection, enumerable: true, configurable: true},
      mode: {value: mode, enumerable: true, configurable: true},
      _: {value: dispatch}
    });
  }

  function nopropagation(event) {
    event.stopImmediatePropagation();
  }

  function noevent(event) {
    event.preventDefault();
    event.stopImmediatePropagation();
  }

  var MODE_DRAG = {name: "drag"},
      MODE_SPACE = {name: "space"},
      MODE_HANDLE = {name: "handle"},
      MODE_CENTER = {name: "center"};

  const {abs: abs$1, max: max$1, min} = Math;

  function number1(e) {
    return [+e[0], +e[1]];
  }

  function number2(e) {
    return [number1(e[0]), number1(e[1])];
  }

  var X = {
    name: "x",
    handles: ["w", "e"].map(type),
    input: function(x, e) { return x == null ? null : [[+x[0], e[0][1]], [+x[1], e[1][1]]]; },
    output: function(xy) { return xy && [xy[0][0], xy[1][0]]; }
  };

  var Y = {
    };

  var cursors = {
    overlay: "crosshair",
    selection: "move",
    n: "ns-resize",
    e: "ew-resize",
    s: "ns-resize",
    w: "ew-resize",
    nw: "nwse-resize",
    ne: "nesw-resize",
    se: "nwse-resize",
    sw: "nesw-resize"
  };

  var flipX = {
    e: "w",
    w: "e",
    nw: "ne",
    ne: "nw",
    se: "sw",
    sw: "se"
  };

  var flipY = {
    n: "s",
    s: "n",
    nw: "sw",
    ne: "se",
    se: "ne",
    sw: "nw"
  };

  var signsX = {
    overlay: 1,
    selection: 1,
    n: null,
    e: 1,
    s: null,
    w: -1,
    nw: -1,
    ne: 1,
    se: 1,
    sw: -1
  };

  var signsY = {
    overlay: 1,
    selection: 1,
    n: -1,
    e: null,
    s: 1,
    w: null,
    nw: -1,
    ne: -1,
    se: 1,
    sw: 1
  };

  function type(t) {
    return {type: t};
  }

  // Ignore right-click, since that should open the context menu.
  function defaultFilter(event) {
    return !event.ctrlKey && !event.button;
  }

  function defaultExtent() {
    var svg = this.ownerSVGElement || this;
    if (svg.hasAttribute("viewBox")) {
      svg = svg.viewBox.baseVal;
      return [[svg.x, svg.y], [svg.x + svg.width, svg.y + svg.height]];
    }
    return [[0, 0], [svg.width.baseVal.value, svg.height.baseVal.value]];
  }

  function defaultTouchable() {
    return navigator.maxTouchPoints || ("ontouchstart" in this);
  }

  // Like d3.local, but with the name “__brush” rather than auto-generated.
  function local(node) {
    while (!node.__brush) if (!(node = node.parentNode)) return;
    return node.__brush;
  }

  function empty(extent) {
    return extent[0][0] === extent[1][0]
        || extent[0][1] === extent[1][1];
  }

  function brushX() {
    return brush(X);
  }

  function brush(dim) {
    var extent = defaultExtent,
        filter = defaultFilter,
        touchable = defaultTouchable,
        keys = true,
        listeners = dispatch("start", "brush", "end"),
        handleSize = 6,
        touchending;

    function brush(group) {
      var overlay = group
          .property("__brush", initialize)
        .selectAll(".overlay")
        .data([type("overlay")]);

      overlay.enter().append("rect")
          .attr("class", "overlay")
          .attr("pointer-events", "all")
          .attr("cursor", cursors.overlay)
        .merge(overlay)
          .each(function() {
            var extent = local(this).extent;
            select(this)
                .attr("x", extent[0][0])
                .attr("y", extent[0][1])
                .attr("width", extent[1][0] - extent[0][0])
                .attr("height", extent[1][1] - extent[0][1]);
          });

      group.selectAll(".selection")
        .data([type("selection")])
        .enter().append("rect")
          .attr("class", "selection")
          .attr("cursor", cursors.selection)
          .attr("fill", "#777")
          .attr("fill-opacity", 0.3)
          .attr("stroke", "#fff")
          .attr("shape-rendering", "crispEdges");

      var handle = group.selectAll(".handle")
        .data(dim.handles, function(d) { return d.type; });

      handle.exit().remove();

      handle.enter().append("rect")
          .attr("class", function(d) { return "handle handle--" + d.type; })
          .attr("cursor", function(d) { return cursors[d.type]; });

      group
          .each(redraw)
          .attr("fill", "none")
          .attr("pointer-events", "all")
          .on("mousedown.brush", started)
        .filter(touchable)
          .on("touchstart.brush", started)
          .on("touchmove.brush", touchmoved)
          .on("touchend.brush touchcancel.brush", touchended)
          .style("touch-action", "none")
          .style("-webkit-tap-highlight-color", "rgba(0,0,0,0)");
    }

    brush.move = function(group, selection, event) {
      if (group.tween) {
        group
            .on("start.brush", function(event) { emitter(this, arguments).beforestart().start(event); })
            .on("interrupt.brush end.brush", function(event) { emitter(this, arguments).end(event); })
            .tween("brush", function() {
              var that = this,
                  state = that.__brush,
                  emit = emitter(that, arguments),
                  selection0 = state.selection,
                  selection1 = dim.input(typeof selection === "function" ? selection.apply(this, arguments) : selection, state.extent),
                  i = interpolate(selection0, selection1);

              function tween(t) {
                state.selection = t === 1 && selection1 === null ? null : i(t);
                redraw.call(that);
                emit.brush();
              }

              return selection0 !== null && selection1 !== null ? tween : tween(1);
            });
      } else {
        group
            .each(function() {
              var that = this,
                  args = arguments,
                  state = that.__brush,
                  selection1 = dim.input(typeof selection === "function" ? selection.apply(that, args) : selection, state.extent),
                  emit = emitter(that, args).beforestart();

              interrupt(that);
              state.selection = selection1 === null ? null : selection1;
              redraw.call(that);
              emit.start(event).brush(event).end(event);
            });
      }
    };

    brush.clear = function(group, event) {
      brush.move(group, null, event);
    };

    function redraw() {
      var group = select(this),
          selection = local(this).selection;

      if (selection) {
        group.selectAll(".selection")
            .style("display", null)
            .attr("x", selection[0][0])
            .attr("y", selection[0][1])
            .attr("width", selection[1][0] - selection[0][0])
            .attr("height", selection[1][1] - selection[0][1]);

        group.selectAll(".handle")
            .style("display", null)
            .attr("x", function(d) { return d.type[d.type.length - 1] === "e" ? selection[1][0] - handleSize / 2 : selection[0][0] - handleSize / 2; })
            .attr("y", function(d) { return d.type[0] === "s" ? selection[1][1] - handleSize / 2 : selection[0][1] - handleSize / 2; })
            .attr("width", function(d) { return d.type === "n" || d.type === "s" ? selection[1][0] - selection[0][0] + handleSize : handleSize; })
            .attr("height", function(d) { return d.type === "e" || d.type === "w" ? selection[1][1] - selection[0][1] + handleSize : handleSize; });
      }

      else {
        group.selectAll(".selection,.handle")
            .style("display", "none")
            .attr("x", null)
            .attr("y", null)
            .attr("width", null)
            .attr("height", null);
      }
    }

    function emitter(that, args, clean) {
      var emit = that.__brush.emitter;
      return emit && (!clean || !emit.clean) ? emit : new Emitter(that, args, clean);
    }

    function Emitter(that, args, clean) {
      this.that = that;
      this.args = args;
      this.state = that.__brush;
      this.active = 0;
      this.clean = clean;
    }

    Emitter.prototype = {
      beforestart: function() {
        if (++this.active === 1) this.state.emitter = this, this.starting = true;
        return this;
      },
      start: function(event, mode) {
        if (this.starting) this.starting = false, this.emit("start", event, mode);
        else this.emit("brush", event);
        return this;
      },
      brush: function(event, mode) {
        this.emit("brush", event, mode);
        return this;
      },
      end: function(event, mode) {
        if (--this.active === 0) delete this.state.emitter, this.emit("end", event, mode);
        return this;
      },
      emit: function(type, event, mode) {
        var d = select(this.that).datum();
        listeners.call(
          type,
          this.that,
          new BrushEvent(type, {
            sourceEvent: event,
            target: brush,
            selection: dim.output(this.state.selection),
            mode,
            dispatch: listeners
          }),
          d
        );
      }
    };

    function started(event) {
      if (touchending && !event.touches) return;
      if (!filter.apply(this, arguments)) return;

      var that = this,
          type = event.target.__data__.type,
          mode = (keys && event.metaKey ? type = "overlay" : type) === "selection" ? MODE_DRAG : (keys && event.altKey ? MODE_CENTER : MODE_HANDLE),
          signX = dim === Y ? null : signsX[type],
          signY = dim === X ? null : signsY[type],
          state = local(that),
          extent = state.extent,
          selection = state.selection,
          W = extent[0][0], w0, w1,
          N = extent[0][1], n0, n1,
          E = extent[1][0], e0, e1,
          S = extent[1][1], s0, s1,
          dx = 0,
          dy = 0,
          moving,
          shifting = signX && signY && keys && event.shiftKey,
          lockX,
          lockY,
          points = Array.from(event.touches || [event], t => {
            const i = t.identifier;
            t = pointer(t, that);
            t.point0 = t.slice();
            t.identifier = i;
            return t;
          });

      interrupt(that);
      var emit = emitter(that, arguments, true).beforestart();

      if (type === "overlay") {
        if (selection) moving = true;
        const pts = [points[0], points[1] || points[0]];
        state.selection = selection = [[
            w0 = dim === Y ? W : min(pts[0][0], pts[1][0]),
            n0 = dim === X ? N : min(pts[0][1], pts[1][1])
          ], [
            e0 = dim === Y ? E : max$1(pts[0][0], pts[1][0]),
            s0 = dim === X ? S : max$1(pts[0][1], pts[1][1])
          ]];
        if (points.length > 1) move(event);
      } else {
        w0 = selection[0][0];
        n0 = selection[0][1];
        e0 = selection[1][0];
        s0 = selection[1][1];
      }

      w1 = w0;
      n1 = n0;
      e1 = e0;
      s1 = s0;

      var group = select(that)
          .attr("pointer-events", "none");

      var overlay = group.selectAll(".overlay")
          .attr("cursor", cursors[type]);

      if (event.touches) {
        emit.moved = moved;
        emit.ended = ended;
      } else {
        var view = select(event.view)
            .on("mousemove.brush", moved, true)
            .on("mouseup.brush", ended, true);
        if (keys) view
            .on("keydown.brush", keydowned, true)
            .on("keyup.brush", keyupped, true);

        dragDisable(event.view);
      }

      redraw.call(that);
      emit.start(event, mode.name);

      function moved(event) {
        for (const p of event.changedTouches || [event]) {
          for (const d of points)
            if (d.identifier === p.identifier) d.cur = pointer(p, that);
        }
        if (shifting && !lockX && !lockY && points.length === 1) {
          const point = points[0];
          if (abs$1(point.cur[0] - point[0]) > abs$1(point.cur[1] - point[1]))
            lockY = true;
          else
            lockX = true;
        }
        for (const point of points)
          if (point.cur) point[0] = point.cur[0], point[1] = point.cur[1];
        moving = true;
        noevent(event);
        move(event);
      }

      function move(event) {
        const point = points[0], point0 = point.point0;
        var t;

        dx = point[0] - point0[0];
        dy = point[1] - point0[1];

        switch (mode) {
          case MODE_SPACE:
          case MODE_DRAG: {
            if (signX) dx = max$1(W - w0, min(E - e0, dx)), w1 = w0 + dx, e1 = e0 + dx;
            if (signY) dy = max$1(N - n0, min(S - s0, dy)), n1 = n0 + dy, s1 = s0 + dy;
            break;
          }
          case MODE_HANDLE: {
            if (points[1]) {
              if (signX) w1 = max$1(W, min(E, points[0][0])), e1 = max$1(W, min(E, points[1][0])), signX = 1;
              if (signY) n1 = max$1(N, min(S, points[0][1])), s1 = max$1(N, min(S, points[1][1])), signY = 1;
            } else {
              if (signX < 0) dx = max$1(W - w0, min(E - w0, dx)), w1 = w0 + dx, e1 = e0;
              else if (signX > 0) dx = max$1(W - e0, min(E - e0, dx)), w1 = w0, e1 = e0 + dx;
              if (signY < 0) dy = max$1(N - n0, min(S - n0, dy)), n1 = n0 + dy, s1 = s0;
              else if (signY > 0) dy = max$1(N - s0, min(S - s0, dy)), n1 = n0, s1 = s0 + dy;
            }
            break;
          }
          case MODE_CENTER: {
            if (signX) w1 = max$1(W, min(E, w0 - dx * signX)), e1 = max$1(W, min(E, e0 + dx * signX));
            if (signY) n1 = max$1(N, min(S, n0 - dy * signY)), s1 = max$1(N, min(S, s0 + dy * signY));
            break;
          }
        }

        if (e1 < w1) {
          signX *= -1;
          t = w0, w0 = e0, e0 = t;
          t = w1, w1 = e1, e1 = t;
          if (type in flipX) overlay.attr("cursor", cursors[type = flipX[type]]);
        }

        if (s1 < n1) {
          signY *= -1;
          t = n0, n0 = s0, s0 = t;
          t = n1, n1 = s1, s1 = t;
          if (type in flipY) overlay.attr("cursor", cursors[type = flipY[type]]);
        }

        if (state.selection) selection = state.selection; // May be set by brush.move!
        if (lockX) w1 = selection[0][0], e1 = selection[1][0];
        if (lockY) n1 = selection[0][1], s1 = selection[1][1];

        if (selection[0][0] !== w1
            || selection[0][1] !== n1
            || selection[1][0] !== e1
            || selection[1][1] !== s1) {
          state.selection = [[w1, n1], [e1, s1]];
          redraw.call(that);
          emit.brush(event, mode.name);
        }
      }

      function ended(event) {
        nopropagation(event);
        if (event.touches) {
          if (event.touches.length) return;
          if (touchending) clearTimeout(touchending);
          touchending = setTimeout(function() { touchending = null; }, 500); // Ghost clicks are delayed!
        } else {
          yesdrag(event.view, moving);
          view.on("keydown.brush keyup.brush mousemove.brush mouseup.brush", null);
        }
        group.attr("pointer-events", "all");
        overlay.attr("cursor", cursors.overlay);
        if (state.selection) selection = state.selection; // May be set by brush.move (on start)!
        if (empty(selection)) state.selection = null, redraw.call(that);
        emit.end(event, mode.name);
      }

      function keydowned(event) {
        switch (event.keyCode) {
          case 16: { // SHIFT
            shifting = signX && signY;
            break;
          }
          case 18: { // ALT
            if (mode === MODE_HANDLE) {
              if (signX) e0 = e1 - dx * signX, w0 = w1 + dx * signX;
              if (signY) s0 = s1 - dy * signY, n0 = n1 + dy * signY;
              mode = MODE_CENTER;
              move(event);
            }
            break;
          }
          case 32: { // SPACE; takes priority over ALT
            if (mode === MODE_HANDLE || mode === MODE_CENTER) {
              if (signX < 0) e0 = e1 - dx; else if (signX > 0) w0 = w1 - dx;
              if (signY < 0) s0 = s1 - dy; else if (signY > 0) n0 = n1 - dy;
              mode = MODE_SPACE;
              overlay.attr("cursor", cursors.selection);
              move(event);
            }
            break;
          }
          default: return;
        }
        noevent(event);
      }

      function keyupped(event) {
        switch (event.keyCode) {
          case 16: { // SHIFT
            if (shifting) {
              lockX = lockY = shifting = false;
              move(event);
            }
            break;
          }
          case 18: { // ALT
            if (mode === MODE_CENTER) {
              if (signX < 0) e0 = e1; else if (signX > 0) w0 = w1;
              if (signY < 0) s0 = s1; else if (signY > 0) n0 = n1;
              mode = MODE_HANDLE;
              move(event);
            }
            break;
          }
          case 32: { // SPACE
            if (mode === MODE_SPACE) {
              if (event.altKey) {
                if (signX) e0 = e1 - dx * signX, w0 = w1 + dx * signX;
                if (signY) s0 = s1 - dy * signY, n0 = n1 + dy * signY;
                mode = MODE_CENTER;
              } else {
                if (signX < 0) e0 = e1; else if (signX > 0) w0 = w1;
                if (signY < 0) s0 = s1; else if (signY > 0) n0 = n1;
                mode = MODE_HANDLE;
              }
              overlay.attr("cursor", cursors[type]);
              move(event);
            }
            break;
          }
          default: return;
        }
        noevent(event);
      }
    }

    function touchmoved(event) {
      emitter(this, arguments).moved(event);
    }

    function touchended(event) {
      emitter(this, arguments).ended(event);
    }

    function initialize() {
      var state = this.__brush || {selection: null};
      state.extent = number2(extent.apply(this, arguments));
      state.dim = dim;
      return state;
    }

    brush.extent = function(_) {
      return arguments.length ? (extent = typeof _ === "function" ? _ : constant$2(number2(_)), brush) : extent;
    };

    brush.filter = function(_) {
      return arguments.length ? (filter = typeof _ === "function" ? _ : constant$2(!!_), brush) : filter;
    };

    brush.touchable = function(_) {
      return arguments.length ? (touchable = typeof _ === "function" ? _ : constant$2(!!_), brush) : touchable;
    };

    brush.handleSize = function(_) {
      return arguments.length ? (handleSize = +_, brush) : handleSize;
    };

    brush.keyModifiers = function(_) {
      return arguments.length ? (keys = !!_, brush) : keys;
    };

    brush.on = function() {
      var value = listeners.on.apply(listeners, arguments);
      return value === listeners ? brush : value;
    };

    return brush;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS$6 = {
      height: 240,
      // Y-axis dropped → no left margin needed for tick labels; keep
      // top room for the floating bar-value labels and bottom room for
      // both the tick labels and the editorial sub-rule (y=26).
      margin: { top: 20, right: 4, bottom: 36, left: 4 },
      orientation: "vertical",
      brush: false,
      barPadding: 0.2,
  };

  const ORIENTATIONS$1 = new Set(["vertical", "horizontal"]);

  /**
   * Bar / histogram widget for categorical `{label, value}` rows.
   * Renders either vertical or horizontal bars; an optional d3-brush
   * lets the consumer drag-select a sub-range and react via the
   * `selectionChanged` CustomEvent on the host target.
   *
   * The widget is deliberately presentation-only: payload arrives
   * pre-aggregated from the consumer (PHP / Stats repo / chart-lib
   * caller) and the bars render in the order they arrive. Bars carry
   * an optional per-row `class` (for CSS palette hooks) and a
   * `tooltip` body that, when set, takes precedence over the default
   * `value.toLocaleString()` rendering — same conventions as
   * {@see LineChart}.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class BarChart extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     margin?: {top: number, right: number, bottom: number, left: number},
       *     orientation?: "vertical" | "horizontal",
       *     brush?: boolean,
       *     barPadding?: number,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive$8(this.options.height, DEFAULT_OPTIONS$6.height);
          this._margin = { ...DEFAULT_OPTIONS$6.margin, ...(this.options.margin ?? {}) };
          this._orientation = ORIENTATIONS$1.has(this.options.orientation)
              ? this.options.orientation
              : DEFAULT_OPTIONS$6.orientation;
          this._brushEnabled =
              typeof this.options.brush === "boolean" ? this.options.brush : DEFAULT_OPTIONS$6.brush;
          this._barPadding = pickFraction$4(this.options.barPadding, DEFAULT_OPTIONS$6.barPadding);
      }

      /**
       * @param {Array<{label: string, value: number, class?: string, tooltip?: string, tooltipLabel?: string}>|null|undefined} data
       *   Categorical rows in display order. `class` is applied as
       *   the `class` attribute on the `<rect>` element so consumer
       *   CSS can colour individual bars. `tooltip` overrides the
       *   default value rendering inside the chart-lib tooltip.
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          if (!Array.isArray(data) || data.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const rows = data
              .filter((row) => row !== null && typeof row === "object")
              .map((row) => ({
                  label: String(row.label ?? ""),
                  value: Number(row.value ?? 0),
                  class: typeof row.class === "string" ? row.class : "",
                  tooltip: typeof row.tooltip === "string" ? row.tooltip : "",
                  tooltipLabel: typeof row.tooltipLabel === "string" ? row.tooltipLabel : "",
              }))
              .filter((row) => row.label !== "" && Number.isFinite(row.value) && row.value >= 0);

          if (rows.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const margin = this._margin;
          const height = this._height;
          const width = Math.max(
              240,
              pickPositive$8(this.options.width, this.target.clientWidth) || 600,
          );
          const innerWidth = width - margin.left - margin.right;
          const innerHeight = height - margin.top - margin.bottom;
          const isVertical = this._orientation === "vertical";

          const categorical = band()
              .domain(rows.map((row) => row.label))
              .range(isVertical ? [0, innerWidth] : [0, innerHeight])
              .padding(this._barPadding);

          const valueMax = max$3(rows, (row) => row.value) ?? 1;
          const linear = linear$1()
              .domain([0, valueMax])
              .nice()
              .range(isVertical ? [innerHeight, 0] : [0, innerWidth]);

          const tooltip = createChartTooltip();

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-bar-chart")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Bar chart");

          const inner = svg.append("g").attr("transform", `translate(${margin.left}, ${margin.top})`);

          // Category (label) axis only — value axis is intentionally
          // omitted to mirror the Editorial histogram look. The baseline
          // is reinforced via CSS `stroke` on the .domain path; ticks
          // and tick-marks are hidden via CSS.
          const categoryAxis = isVertical ? axisBottom(categorical) : axisLeft(categorical);

          const categoryAxisGroup = inner
              .append("g")
              .attr("class", isVertical ? "x-axis" : "y-axis")
              .attr("transform", isVertical ? `translate(0, ${innerHeight})` : "translate(0, 0)")
              .call(categoryAxis);

          // Editorial layout: drop the axis baseline entirely (D3's
          // `path.domain`) and the per-tick stubs (`g.tick line`) —
          // both are hidden via CSS. The only visible chrome is a
          // single faint horizontal rule rendered *below* the tick
          // labels, which closes the histogram block off from the
          // section divider that follows.
          if (isVertical) {
              categoryAxisGroup
                  .append("line")
                  .attr("class", "x-axis-rule")
                  .attr("x1", 0)
                  .attr("x2", innerWidth)
                  .attr("y1", 26)
                  .attr("y2", 26);
          }

          // SVG rect's `rx`/`ry` round all four corners; the mockup
          // only rounds the two top corners so the bar sits flush on
          // the x-axis baseline. We render the column as a <path> with
          // a manually-built `d` that only arcs the top edge — keeps
          // the bottom square against the axis line.
          const bars = inner
              .append("g")
              .attr("class", "bars")
              .selectAll("path.bar")
              .data(rows)
              .enter()
              .append("path")
              .attr("class", (row) => (row.class === "" ? "bar" : `bar ${row.class}`))
              .attr("tabindex", "0")
              .attr("aria-label", (row) => `${row.label}: ${row.value.toLocaleString()}`);

          /**
           * Build the path data for a single vertical bar with rounded
           * top corners only.
           *
           * Value 0 renders a 1-px stub sitting on the baseline so
           * empty bands stay visible — the tick still tells the
           * reader "this band exists, nobody in it" instead of
           * dropping silently.
           *
           * Tiny non-zero values (height < 2 px) clamp to a 2-px
           * mini-bar so a single occurrence stays distinguishable
           * from an empty bucket even when the scale is dominated by
           * a huge value next to it (e.g. 1 individual vs 1,000+).
           */
          const topRoundedBar = (xPos, width, _yTop, heightPx, radius) => {
              if (heightPx <= 0) {
                  return `M${xPos},${innerHeight - 1}H${xPos + width}V${innerHeight}H${xPos}Z`;
              }
              const effectiveHeight = Math.max(heightPx, 2);
              const effectiveTop    = innerHeight - effectiveHeight;
              const r               = Math.min(radius, width / 2, effectiveHeight);
              return `M${xPos},${effectiveTop + effectiveHeight}`
                  + `V${effectiveTop + r}`
                  + `Q${xPos},${effectiveTop} ${xPos + r},${effectiveTop}`
                  + `H${xPos + width - r}`
                  + `Q${xPos + width},${effectiveTop} ${xPos + width},${effectiveTop + r}`
                  + `V${effectiveTop + effectiveHeight}`
                  + `Z`;
          };

          if (isVertical) {
              // Cap each bar at the mockup's 56 px and centre it within
              // the band so wide-card histograms (few categories, lots
              // of horizontal room) don't render block-thick columns.
              const MAX_BAR_WIDTH = 56;
              const barWidth = Math.min(categorical.bandwidth(), MAX_BAR_WIDTH);
              const inset = (categorical.bandwidth() - barWidth) / 2;
              const barRadius = 4;

              bars.attr("d", (row) => {
                  const xPos = (categorical(row.label) ?? 0) + inset;
                  const yTop = linear(row.value);
                  const heightPx = innerHeight - yTop;
                  return topRoundedBar(xPos, barWidth, yTop, heightPx, barRadius);
              });
          } else {
              // Horizontal layout: render as plain rectangles (no
              // mockup precedent for rounded ends on horizontal bars).
              bars.attr("d", (row) => {
                  const yPos = categorical(row.label) ?? 0;
                  const widthPx = linear(row.value);
                  const heightPx = categorical.bandwidth();
                  return `M0,${yPos}H${widthPx}V${yPos + heightPx}H0Z`;
              });
          }

          // Value label above each bar — mirrors the histogram mockup
          // where the count floats over the bar instead of relying on
          // a y-axis to be read off.
          if (isVertical) {
              inner
                  .append("g")
                  .attr("class", "bar-values")
                  .selectAll("text.bar-value")
                  .data(rows)
                  .enter()
                  .append("text")
                  .attr("class", "bar-value")
                  .attr("x", (row) => (categorical(row.label) ?? 0) + categorical.bandwidth() / 2)
                  .attr("y", (row) => linear(row.value) - 6)
                  .attr("text-anchor", "middle")
                  .text((row) => (row.value > 0 ? row.value.toLocaleString() : ""));
          }

          bars.on("mouseover", (event, row) => {
              const header = row.tooltipLabel === "" ? row.label : row.tooltipLabel;
              const body =
                  row.tooltip === ""
                      ? escapeHtml(row.value.toLocaleString())
                      : escapeHtml(row.tooltip);
              tooltip.show(
                  event,
                  `<strong>${escapeHtml(header)}</strong><br>` +
                      `<span class="wt-chart-tooltip__stat">${body}</span>`,
              );
          })
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          // Click → toggle selection on the row label. Mirrors the
          // DonutChart contract so the dashboard-bus consumer can
          // bind one onSelectionChanged callback against both.
          const self = this;
          bars.style("cursor", "pointer").on("click", function onClick(_event, row) {
              const { predicate } = self._emitSelection({ label: row.label });
              self._applyBarSelectionStyles(bars, predicate);
          });

          if (this._brushEnabled) {
              this._attachBrush(inner, categorical, rows, isVertical, innerWidth, innerHeight);
          }

          return svg.node();
      }

      /**
       * Toggle the `.is-selected` class on whichever bar matches the
       * current predicate; cleared selection removes the class from
       * every bar. Visual dim of the non-selected bars is a host-
       * stylesheet concern via `:has(.is-selected) :not(.is-selected)`,
       * mirroring the existing hover-dim CSS.
       *
       * @param {import("d3-selection").Selection<SVGRectElement, {label: string}, SVGGElement, unknown>} bars
       * @param {object|null} predicate
       */
      _applyBarSelectionStyles(bars, predicate) {
          if (predicate === null) {
              bars.classed("is-selected", false);
              return;
          }
          bars.classed("is-selected", (row) => row.label === predicate.label);
      }

      /**
       * Attach a d3-brush along the categorical axis. The brush
       * emits a `selectionChanged` CustomEvent on the host element
       * with `detail = { labels: string[] }` so the consumer can
       * cross-filter without depending on d3 internals.
       *
       * @param {import("d3-selection").Selection<SVGGElement, unknown, null, undefined>} inner
       * @param {import("d3-scale").ScaleBand<string>} categorical
       * @param {Array<{label: string}>} rows
       * @param {boolean} isVertical
       * @param {number} innerWidth
       * @param {number} innerHeight
       */
      _attachBrush(inner, categorical, rows, isVertical, innerWidth, innerHeight) {
          const brushAxisLength = isVertical ? innerWidth : innerHeight;
          const target = this.target;

          const brush = brushX().extent([
              [0, 0],
              isVertical ? [innerWidth, innerHeight] : [innerHeight, innerWidth],
          ]);

          brush.on("end", (event) => {
              if (event.selection === null) {
                  target.dispatchEvent(
                      new CustomEvent("selectionChanged", {
                          detail: { labels: [] },
                      }),
                  );
                  return;
              }

              const [lo, hi] = event.selection;
              const selectedLabels = rows
                  .map((row) => row.label)
                  .filter((label) => {
                      const start = categorical(label);
                      if (typeof start !== "number") {
                          return false;
                      }
                      const end = start + categorical.bandwidth();
                      return end > lo && start < hi;
                  });

              target.dispatchEvent(
                  new CustomEvent("selectionChanged", {
                      detail: { labels: selectedLabels },
                  }),
              );
          });

          const brushLayer = inner.append("g").attr("class", "bar-brush");

          if (!isVertical) {
              // For horizontal bars the brush runs along the value
              // axis (visually horizontal), so the categorical-axis
              // length is `innerHeight`; rotate the brush group to
              // align with the categorical axis.
              brushLayer.attr("transform", `rotate(90) translate(0, -${brushAxisLength})`);
          }

          brushLayer.call(brush);
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-bar-chart, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$8(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * Clamp a fraction option into `[0, 0.95]`. Padding values outside
   * that range either dissolve the bars (0.95+ leaves nothing visible)
   * or clip them. Falls back to `defaultValue` for non-numeric input.
   *
   * @param {unknown} value
   * @param {number}  defaultValue
   *
   * @returns {number}
   */
  function pickFraction$4(value, defaultValue) {
      if (typeof value !== "number" || !Number.isFinite(value)) {
          return defaultValue;
      }
      if (value < 0) {
          return 0;
      }
      if (value > 0.95) {
          return 0.95;
      }
      return value;
  }

  var abs = Math.abs;
  var cos = Math.cos;
  var sin = Math.sin;
  var pi$1 = Math.PI;
  var halfPi = pi$1 / 2;
  var tau$1 = pi$1 * 2;
  var max = Math.max;
  var epsilon$1 = 1e-12;

  function range(i, j) {
    return Array.from({length: j - i}, (_, k) => i + k);
  }

  function compareValue(compare) {
    return function(a, b) {
      return compare(
        a.source.value + a.target.value,
        b.source.value + b.target.value
      );
    };
  }

  function d3Chord() {
    return chord(false);
  }

  function chord(directed, transpose) {
    var padAngle = 0,
        sortGroups = null,
        sortSubgroups = null,
        sortChords = null;

    function chord(matrix) {
      var n = matrix.length,
          groupSums = new Array(n),
          groupIndex = range(0, n),
          chords = new Array(n * n),
          groups = new Array(n),
          k = 0, dx;

      matrix = Float64Array.from({length: n * n}, (_, i) => matrix[i / n | 0][i % n]);

      // Compute the scaling factor from value to angle in [0, 2pi].
      for (let i = 0; i < n; ++i) {
        let x = 0;
        for (let j = 0; j < n; ++j) x += matrix[i * n + j] + directed * matrix[j * n + i];
        k += groupSums[i] = x;
      }
      k = max(0, tau$1 - padAngle * n) / k;
      dx = k ? padAngle : tau$1 / n;

      // Compute the angles for each group and constituent chord.
      {
        let x = 0;
        if (sortGroups) groupIndex.sort((a, b) => sortGroups(groupSums[a], groupSums[b]));
        for (const i of groupIndex) {
          const x0 = x;
          {
            const subgroupIndex = range(0, n).filter(j => matrix[i * n + j] || matrix[j * n + i]);
            if (sortSubgroups) subgroupIndex.sort((a, b) => sortSubgroups(matrix[i * n + a], matrix[i * n + b]));
            for (const j of subgroupIndex) {
              let chord;
              if (i < j) {
                chord = chords[i * n + j] || (chords[i * n + j] = {source: null, target: null});
                chord.source = {index: i, startAngle: x, endAngle: x += matrix[i * n + j] * k, value: matrix[i * n + j]};
              } else {
                chord = chords[j * n + i] || (chords[j * n + i] = {source: null, target: null});
                chord.target = {index: i, startAngle: x, endAngle: x += matrix[i * n + j] * k, value: matrix[i * n + j]};
                if (i === j) chord.source = chord.target;
              }
              if (chord.source && chord.target && chord.source.value < chord.target.value) {
                const source = chord.source;
                chord.source = chord.target;
                chord.target = source;
              }
            }
            groups[i] = {index: i, startAngle: x0, endAngle: x, value: groupSums[i]};
          }
          x += dx;
        }
      }

      // Remove empty chords.
      chords = Object.values(chords);
      chords.groups = groups;
      return sortChords ? chords.sort(sortChords) : chords;
    }

    chord.padAngle = function(_) {
      return arguments.length ? (padAngle = max(0, _), chord) : padAngle;
    };

    chord.sortGroups = function(_) {
      return arguments.length ? (sortGroups = _, chord) : sortGroups;
    };

    chord.sortSubgroups = function(_) {
      return arguments.length ? (sortSubgroups = _, chord) : sortSubgroups;
    };

    chord.sortChords = function(_) {
      return arguments.length ? (_ == null ? sortChords = null : (sortChords = compareValue(_))._ = _, chord) : sortChords && sortChords._;
    };

    return chord;
  }

  const pi = Math.PI,
      tau = 2 * pi,
      epsilon = 1e-6,
      tauEpsilon = tau - epsilon;

  function append(strings) {
    this._ += strings[0];
    for (let i = 1, n = strings.length; i < n; ++i) {
      this._ += arguments[i] + strings[i];
    }
  }

  function appendRound(digits) {
    let d = Math.floor(digits);
    if (!(d >= 0)) throw new Error(`invalid digits: ${digits}`);
    if (d > 15) return append;
    const k = 10 ** d;
    return function(strings) {
      this._ += strings[0];
      for (let i = 1, n = strings.length; i < n; ++i) {
        this._ += Math.round(arguments[i] * k) / k + strings[i];
      }
    };
  }

  class Path {
    constructor(digits) {
      this._x0 = this._y0 = // start of current subpath
      this._x1 = this._y1 = null; // end of current subpath
      this._ = "";
      this._append = digits == null ? append : appendRound(digits);
    }
    moveTo(x, y) {
      this._append`M${this._x0 = this._x1 = +x},${this._y0 = this._y1 = +y}`;
    }
    closePath() {
      if (this._x1 !== null) {
        this._x1 = this._x0, this._y1 = this._y0;
        this._append`Z`;
      }
    }
    lineTo(x, y) {
      this._append`L${this._x1 = +x},${this._y1 = +y}`;
    }
    quadraticCurveTo(x1, y1, x, y) {
      this._append`Q${+x1},${+y1},${this._x1 = +x},${this._y1 = +y}`;
    }
    bezierCurveTo(x1, y1, x2, y2, x, y) {
      this._append`C${+x1},${+y1},${+x2},${+y2},${this._x1 = +x},${this._y1 = +y}`;
    }
    arcTo(x1, y1, x2, y2, r) {
      x1 = +x1, y1 = +y1, x2 = +x2, y2 = +y2, r = +r;

      // Is the radius negative? Error.
      if (r < 0) throw new Error(`negative radius: ${r}`);

      let x0 = this._x1,
          y0 = this._y1,
          x21 = x2 - x1,
          y21 = y2 - y1,
          x01 = x0 - x1,
          y01 = y0 - y1,
          l01_2 = x01 * x01 + y01 * y01;

      // Is this path empty? Move to (x1,y1).
      if (this._x1 === null) {
        this._append`M${this._x1 = x1},${this._y1 = y1}`;
      }

      // Or, is (x1,y1) coincident with (x0,y0)? Do nothing.
      else if (!(l01_2 > epsilon));

      // Or, are (x0,y0), (x1,y1) and (x2,y2) collinear?
      // Equivalently, is (x1,y1) coincident with (x2,y2)?
      // Or, is the radius zero? Line to (x1,y1).
      else if (!(Math.abs(y01 * x21 - y21 * x01) > epsilon) || !r) {
        this._append`L${this._x1 = x1},${this._y1 = y1}`;
      }

      // Otherwise, draw an arc!
      else {
        let x20 = x2 - x0,
            y20 = y2 - y0,
            l21_2 = x21 * x21 + y21 * y21,
            l20_2 = x20 * x20 + y20 * y20,
            l21 = Math.sqrt(l21_2),
            l01 = Math.sqrt(l01_2),
            l = r * Math.tan((pi - Math.acos((l21_2 + l01_2 - l20_2) / (2 * l21 * l01))) / 2),
            t01 = l / l01,
            t21 = l / l21;

        // If the start tangent is not coincident with (x0,y0), line to.
        if (Math.abs(t01 - 1) > epsilon) {
          this._append`L${x1 + t01 * x01},${y1 + t01 * y01}`;
        }

        this._append`A${r},${r},0,0,${+(y01 * x20 > x01 * y20)},${this._x1 = x1 + t21 * x21},${this._y1 = y1 + t21 * y21}`;
      }
    }
    arc(x, y, r, a0, a1, ccw) {
      x = +x, y = +y, r = +r, ccw = !!ccw;

      // Is the radius negative? Error.
      if (r < 0) throw new Error(`negative radius: ${r}`);

      let dx = r * Math.cos(a0),
          dy = r * Math.sin(a0),
          x0 = x + dx,
          y0 = y + dy,
          cw = 1 ^ ccw,
          da = ccw ? a0 - a1 : a1 - a0;

      // Is this path empty? Move to (x0,y0).
      if (this._x1 === null) {
        this._append`M${x0},${y0}`;
      }

      // Or, is (x0,y0) not coincident with the previous point? Line to (x0,y0).
      else if (Math.abs(this._x1 - x0) > epsilon || Math.abs(this._y1 - y0) > epsilon) {
        this._append`L${x0},${y0}`;
      }

      // Is this arc empty? We’re done.
      if (!r) return;

      // Does the angle go the wrong way? Flip the direction.
      if (da < 0) da = da % tau + tau;

      // Is this a complete circle? Draw two arcs to complete the circle.
      if (da > tauEpsilon) {
        this._append`A${r},${r},0,1,${cw},${x - dx},${y - dy}A${r},${r},0,1,${cw},${this._x1 = x0},${this._y1 = y0}`;
      }

      // Is this arc non-empty? Draw an arc!
      else if (da > epsilon) {
        this._append`A${r},${r},0,${+(da >= pi)},${cw},${this._x1 = x + r * Math.cos(a1)},${this._y1 = y + r * Math.sin(a1)}`;
      }
    }
    rect(x, y, w, h) {
      this._append`M${this._x0 = this._x1 = +x},${this._y0 = this._y1 = +y}h${w = +w}v${+h}h${-w}Z`;
    }
    toString() {
      return this._;
    }
  }

  function path() {
    return new Path;
  }

  // Allow instanceof d3.path
  path.prototype = Path.prototype;

  var slice = Array.prototype.slice;

  function constant$1(x) {
    return function() {
      return x;
    };
  }

  function defaultSource(d) {
    return d.source;
  }

  function defaultTarget(d) {
    return d.target;
  }

  function defaultRadius(d) {
    return d.radius;
  }

  function defaultStartAngle(d) {
    return d.startAngle;
  }

  function defaultEndAngle(d) {
    return d.endAngle;
  }

  function defaultPadAngle() {
    return 0;
  }

  function ribbon(headRadius) {
    var source = defaultSource,
        target = defaultTarget,
        sourceRadius = defaultRadius,
        targetRadius = defaultRadius,
        startAngle = defaultStartAngle,
        endAngle = defaultEndAngle,
        padAngle = defaultPadAngle,
        context = null;

    function ribbon() {
      var buffer,
          s = source.apply(this, arguments),
          t = target.apply(this, arguments),
          ap = padAngle.apply(this, arguments) / 2,
          argv = slice.call(arguments),
          sr = +sourceRadius.apply(this, (argv[0] = s, argv)),
          sa0 = startAngle.apply(this, argv) - halfPi,
          sa1 = endAngle.apply(this, argv) - halfPi,
          tr = +targetRadius.apply(this, (argv[0] = t, argv)),
          ta0 = startAngle.apply(this, argv) - halfPi,
          ta1 = endAngle.apply(this, argv) - halfPi;

      if (!context) context = buffer = path();

      if (ap > epsilon$1) {
        if (abs(sa1 - sa0) > ap * 2 + epsilon$1) sa1 > sa0 ? (sa0 += ap, sa1 -= ap) : (sa0 -= ap, sa1 += ap);
        else sa0 = sa1 = (sa0 + sa1) / 2;
        if (abs(ta1 - ta0) > ap * 2 + epsilon$1) ta1 > ta0 ? (ta0 += ap, ta1 -= ap) : (ta0 -= ap, ta1 += ap);
        else ta0 = ta1 = (ta0 + ta1) / 2;
      }

      context.moveTo(sr * cos(sa0), sr * sin(sa0));
      context.arc(0, 0, sr, sa0, sa1);
      if (sa0 !== ta0 || sa1 !== ta1) {
        {
          context.quadraticCurveTo(0, 0, tr * cos(ta0), tr * sin(ta0));
          context.arc(0, 0, tr, ta0, ta1);
        }
      }
      context.quadraticCurveTo(0, 0, sr * cos(sa0), sr * sin(sa0));
      context.closePath();

      if (buffer) return context = null, buffer + "" || null;
    }

    ribbon.radius = function(_) {
      return arguments.length ? (sourceRadius = targetRadius = typeof _ === "function" ? _ : constant$1(+_), ribbon) : sourceRadius;
    };

    ribbon.sourceRadius = function(_) {
      return arguments.length ? (sourceRadius = typeof _ === "function" ? _ : constant$1(+_), ribbon) : sourceRadius;
    };

    ribbon.targetRadius = function(_) {
      return arguments.length ? (targetRadius = typeof _ === "function" ? _ : constant$1(+_), ribbon) : targetRadius;
    };

    ribbon.startAngle = function(_) {
      return arguments.length ? (startAngle = typeof _ === "function" ? _ : constant$1(+_), ribbon) : startAngle;
    };

    ribbon.endAngle = function(_) {
      return arguments.length ? (endAngle = typeof _ === "function" ? _ : constant$1(+_), ribbon) : endAngle;
    };

    ribbon.padAngle = function(_) {
      return arguments.length ? (padAngle = typeof _ === "function" ? _ : constant$1(+_), ribbon) : padAngle;
    };

    ribbon.source = function(_) {
      return arguments.length ? (source = _, ribbon) : source;
    };

    ribbon.target = function(_) {
      return arguments.length ? (target = _, ribbon) : target;
    };

    ribbon.context = function(_) {
      return arguments.length ? ((context = _ == null ? null : _), ribbon) : context;
    };

    return ribbon;
  }

  function ribbon$1() {
    return ribbon();
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS$4 = {
      // 600 leaves comfortable room for both the arc circle AND its
      // outer labels — anything below ~440 squashed 90°-rotated
      // labels at top/bottom against the SVG edge; 600 gives the
      // arc band itself enough diameter to read at a glance on a
      // full-width statistics card.
      height: 600,
      padAngle: 0.04,
  };

  /**
   * Whitelist for the `classes[i]` payload field — accepts one or
   * more standard CSS-identifier tokens separated by whitespace.
   * Anything else (e.g. `"x onclick=alert(1)"`) is dropped at
   * normalisation time so the hostile token cannot ride into the
   * arc's `class` attribute.
   */
  const CLASS_TOKEN_LIST = /^[A-Za-z_][A-Za-z0-9_-]*(\s+[A-Za-z_][A-Za-z0-9_-]*)*$/;

  /**
   * Chord diagram (circular arcs + ribbons) for symmetric N×N
   * matrix payloads. Each arc represents one category; the
   * ribbon between two arcs encodes the connection strength
   * between them. Used for surname-pair distributions, family-
   * by-family kinship density, and any payload where the
   * interesting view is "who connects to whom" rather than
   * "how much per category".
   *
   * The widget assumes a symmetric matrix (marriage A↔B same as
   * B↔A); it does not enforce it, but unbalanced input renders
   * ribbon thickness based on row sums per d3-chord's contract.
   * Hovering a ribbon dims everything else so the visual chain
   * becomes traceable in a dense diagram.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class ChordDiagram extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     padAngle?: number,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive$6(this.options.height, DEFAULT_OPTIONS$4.height);
          this._padAngle = pickFraction$2(this.options.padAngle, DEFAULT_OPTIONS$4.padAngle);
      }

      /**
       * @param {{
       *     labels: string[],
       *     matrix: number[][],
       *     classes?: string[]
       * }|null|undefined} data
       *   `labels[i]` names the i-th arc. `matrix[i][j]` is the
       *   connection strength from i to j; the widget treats it
       *   as symmetric. Optional per-arc `classes[i]` overrides
       *   the schemeTableau10 fallback fill via a CSS class hook.
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const validated = this._validate(data);
          if (validated === null) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const { labels, matrix, classes } = validated;
          const height = this._height;
          const width = Math.max(
              240,
              pickPositive$6(this.options.width, this.target.clientWidth) || height,
          );
          const size = Math.min(width, height);
          // Outer padding holds the arc-tip labels. Each label sits at
          // `outerRadius + 6` and extends outwards by roughly its
          // pixel-length (10–14 chars × 7px / char ≈ 100px). A flat 24px
          // padding clipped longer surname labels at the SVG bounds;
          // 88px keeps eight-character labels fully visible on the
          // default 360×360 viewBox without forcing every consumer to
          // grow the container.
          const labelPadding = 88;
          const outerRadius = size / 2 - labelPadding;
          const innerRadius = outerRadius - 12;

          const chordLayout = d3Chord()
              .padAngle(this._padAngle)
              .sortSubgroups((a, b) => b - a);
          const chords = chordLayout(matrix);

          const colour = ordinal().domain(labels).range(schemeTableau10);
          const tooltip = createChartTooltip();

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-chord-diagram")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Chord diagram");

          const root = svg
              .append("g")
              .attr("class", "chord-root")
              .attr("transform", `translate(${width / 2}, ${height / 2})`);

          const arcGenerator = arc().innerRadius(innerRadius).outerRadius(outerRadius);
          const ribbonGenerator = ribbon$1().radius(innerRadius);

          // Arc groups — one per category.
          const groups = root
              .append("g")
              .attr("class", "arcs")
              .selectAll("g.arc")
              .data(chords.groups)
              .enter()
              .append("g")
              .attr("class", (d) => {
                  const cls = classes[d.index] ?? "";
                  return cls === "" ? "arc" : `arc ${cls}`;
              })
              .attr("data-label", (d) => labels[d.index] ?? "");

          groups
              .append("path")
              .attr("class", "arc-path")
              .attr("d", arcGenerator)
              // .style() so the computed scale colour wins against any
              // .arc-path CSS rule downstream; consumers that supply a
              // class via `classes[i]` get null here so the stylesheet
              // wins instead.
              .style("fill", (d) =>
                  classes[d.index] === "" ? (colour(labels[d.index] ?? "") ?? "") : null,
              )
              .attr("tabindex", "0")
              .attr("aria-label", (d) => {
                  const label = labels[d.index] ?? "";
                  const total = d.value ?? 0;
                  return `${label}: ${total.toLocaleString()}`;
              });

          // Arc labels. dominant-baseline keeps the text centred on
          // the radial anchor across redraws (dy="0.35em" compounds
          // with any host stylesheet line-height override).
          groups
              .append("text")
              .attr("class", "arc-label")
              .attr("dominant-baseline", "middle")
              .attr("text-anchor", (d) =>
                  (d.startAngle + d.endAngle) / 2 > Math.PI ? "end" : "start",
              )
              .attr("transform", (d) => {
                  const angle = (d.startAngle + d.endAngle) / 2;
                  const rotate = (angle * 180) / Math.PI - 90;
                  const flip = angle > Math.PI ? "rotate(180)" : "";
                  return `rotate(${rotate}) translate(${outerRadius + 6}, 0) ${flip}`;
              })
              .text((d) => labels[d.index] ?? "");

          // Ribbons.
          const ribbons = root
              .append("g")
              .attr("class", "ribbons")
              .selectAll("path.ribbon")
              .data(chords)
              .enter()
              .append("path")
              .attr("class", "ribbon")
              .attr("d", ribbonGenerator)
              .style("fill", (d) =>
                  classes[d.source.index] === ""
                      ? (colour(labels[d.source.index] ?? "") ?? "")
                      : null,
              )
              // .style("opacity") so the hover-dim presentation value
              // beats any default `.ribbon { opacity }` rule a consumer
              // stylesheet might ship.
              .style("opacity", 0.6)
              .attr("data-source", (d) => labels[d.source.index] ?? "")
              .attr("data-target", (d) => labels[d.target.index] ?? "")
              .attr("tabindex", "0")
              .attr("aria-label", (d) => {
                  const source = labels[d.source.index] ?? "";
                  const target = labels[d.target.index] ?? "";
                  const value = d.source.value ?? 0;
                  return `${source} ↔ ${target}: ${value.toLocaleString()}`;
              });

          const i18n = this.options.i18n ?? {};
          const ribbonValueLabel = (value) => {
              const template = value === 1
                  ? (i18n.tooltipValueSingular ?? "{count}")
                  : (i18n.tooltipValuePlural ?? "{count}");
              return template.replace("{count}", value.toLocaleString());
          };
          ribbons
              .on("mouseover", (event, d) => {
                  const source = String(labels[d.source.index] ?? "");
                  const target = String(labels[d.target.index] ?? "");
                  const value = Number(d.source.value ?? 0);
                  tooltip.show(
                      event,
                      `<strong>${escapeHtml(source)} ↔ ${escapeHtml(target)}</strong><br>` +
                          `<span class="wt-chart-tooltip__stat">${escapeHtml(ribbonValueLabel(value))}</span>`,
                  );
                  ribbons.style("opacity", 0.1);
                  select(event.currentTarget).style("opacity", 0.9);
              })
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => {
                  tooltip.hide();
                  ribbons.style("opacity", 0.6);
              });

          return svg.node();
      }

      /**
       * Validate the input payload into `{labels, matrix, classes}`
       * where matrix is square and every row has the same number
       * of columns as labels. Returns null to signal the empty
       * state path.
       *
       * @param {unknown} data
       *
       * @returns {{labels: string[], matrix: number[][], classes: string[]}|null}
       */
      _validate(data) {
          if (data === null || data === undefined || typeof data !== "object") {
              return null;
          }
          const labels = Array.isArray(data.labels)
              ? data.labels.filter((label) => typeof label === "string" && label !== "")
              : [];
          const rawMatrix = Array.isArray(data.matrix) ? data.matrix : [];
          const rawClasses = Array.isArray(data.classes) ? data.classes : [];

          if (labels.length < 2 || rawMatrix.length !== labels.length) {
              return null;
          }

          const matrix = rawMatrix.map((row) => {
              if (!Array.isArray(row)) {
                  return labels.map(() => 0);
              }
              return labels.map((_, index) => {
                  const value = Number(row[index] ?? 0);
                  return Number.isFinite(value) && value >= 0 ? value : 0;
              });
          });

          const anyConnection = matrix.some((row, i) => row.some((value, j) => i !== j && value > 0));
          if (!anyConnection) {
              return null;
          }

          // Class tokens are whitespace-separated CSS identifiers; the
          // allowlist regex rejects anything that does not look like a
          // standard CSS class token list so a hostile payload cannot
          // smuggle additional attributes via the class hook.
          const classes = labels.map((_, index) => {
              const raw = rawClasses[index];
              if (typeof raw !== "string" || raw === "") {
                  return "";
              }
              return CLASS_TOKEN_LIST.test(raw) ? raw : "";
          });

          return { labels, matrix, classes };
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-chord-diagram, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$6(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * @param {unknown} value
   * @param {number}  defaultValue
   *
   * @returns {number}
   */
  function pickFraction$2(value, defaultValue) {
      // d3-chord's recommended padAngle ceiling is around 0.5 rad —
      // beyond that the gaps eat into the arc thickness and the
      // diagram becomes unreadable.
      if (typeof value !== "number" || !Number.isFinite(value)) {
          return defaultValue;
      }
      if (value < 0) {
          return 0;
      }
      if (value > 0.5) {
          return 0.5;
      }
      return value;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS$3 = {
      rowHeight: 22,
      barHeight: 14,
      barRadius: 2,
      paddingY: 8,
      centerColumnWidth: 70,
      valueTextWidth: 28,
      barFraction: 0.48,
  };

  /**
   * Diverging bar chart styled as the design2 reference — each row
   * lays out as a 3-column band: left-anchored bar (negative side),
   * central separator label (the bucket label flanked by hairline
   * rules), right-anchored bar (positive side). No x-axis. The bar
   * length encodes `value / maxValue` against a per-side `barFraction`
   * of the side-column width.
   *
   * Caller supplies rows in display order (top → bottom). Each row's
   * `sign` (`-1` or `+1`) decides which side the bar grows toward.
   *
   * Structure (mirrors the g-grouping convention from
   * mirror-histogram): outer `<g.wt-diverging-inner>` wraps three
   * named sub-groups — `wt-diverging-rules` (centre separator rules),
   * `wt-diverging-bars-left` (negative-sign bars + their values),
   * `wt-diverging-bars-right` (positive-sign bars + their values),
   * and `wt-diverging-labels` (the bucket labels in the centre).
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class DivergingBar extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     width?: number,
       *     rowHeight?: number,
       *     barHeight?: number,
       *     barRadius?: number,
       *     paddingY?: number,
       *     centerColumnWidth?: number,
       *     valueTextWidth?: number,
       *     barFraction?: number,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._rowHeight = pickPositive$5(this.options.rowHeight, DEFAULT_OPTIONS$3.rowHeight);
          this._barHeight = pickPositive$5(this.options.barHeight, DEFAULT_OPTIONS$3.barHeight);
          this._barRadius = pickPositive$5(this.options.barRadius, DEFAULT_OPTIONS$3.barRadius);
          this._paddingY = pickPositive$5(this.options.paddingY, DEFAULT_OPTIONS$3.paddingY);
          this._centerColumnWidth = pickPositive$5(
              this.options.centerColumnWidth,
              DEFAULT_OPTIONS$3.centerColumnWidth,
          );
          this._valueTextWidth = pickPositive$5(
              this.options.valueTextWidth,
              DEFAULT_OPTIONS$3.valueTextWidth,
          );
          this._barFraction = pickFraction$1(
              this.options.barFraction,
              DEFAULT_OPTIONS$3.barFraction,
          );
      }

      /**
       * @param {Array<{label: string, value: number, sign: -1|1, tooltip?: string, tooltipLabel?: string}>|null|undefined} data
       *   Categorical rows in display order. `value` must be
       *   non-negative; the caller's `sign` (-1 or +1) controls which
       *   side of the centre column the bar grows toward.
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          if (!Array.isArray(data) || data.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const rows = data
              .filter((row) => row !== null && typeof row === "object")
              .map((row) => ({
                  label: String(row.label ?? ""),
                  value: Number(row.value ?? 0),
                  sign: row.sign === -1 ? -1 : 1,
                  tooltip: typeof row.tooltip === "string" ? row.tooltip : "",
                  tooltipLabel: typeof row.tooltipLabel === "string" ? row.tooltipLabel : "",
              }))
              .filter((row) => row.label !== "" && Number.isFinite(row.value) && row.value >= 0);

          if (rows.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          // Every retained row carries value=0 → there is nothing to
          // draw. Fall through to the empty-state placeholder so the
          // card body doesn't render a blank centre column.
          if (rows.every((row) => row.value === 0)) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const W = Math.max(
              300,
              pickPositive$5(this.options.width, this.target.clientWidth) || 720,
          );
          const rowH = this._rowHeight;
          const barH = this._barHeight;
          const barRadius = this._barRadius;
          const paddingY = this._paddingY;
          const H = rows.length * rowH + paddingY * 2;

          const centerColWidth = this._centerColumnWidth;
          const valueTextWidth = this._valueTextWidth;
          const sideGutter = 8;

          const centerX = W / 2;
          const centerLeftEdge = centerX - centerColWidth / 2;
          const centerRightEdge = centerX + centerColWidth / 2;

          // Inner anchor X for the per-side value text (sits flush
          // against the centre column on the inside of each side).
          const leftValueAnchorX = centerLeftEdge - sideGutter;
          const rightValueAnchorX = centerRightEdge + sideGutter;

          // Maximum bar width = `barFraction` of the remaining side
          // width after the value-text gutter. Caller-tunable via the
          // `barFraction` option (defaults to 0.48 ≈ design2).
          const leftSideAvailable = leftValueAnchorX - valueTextWidth - sideGutter;
          const rightSideAvailable = W - rightValueAnchorX - valueTextWidth - sideGutter;
          const maxBarWidth = Math.max(
              0,
              Math.min(leftSideAvailable, rightSideAvailable) * this._barFraction,
          );

          const valueMax = max$3(rows, (row) => row.value) || 1;
          const barWidthFor = (value) => (valueMax > 0 ? (value / valueMax) * maxBarWidth : 0);

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-diverging-bar")
              .attr("viewBox", `0 0 ${W} ${H}`)
              .attr("preserveAspectRatio", "xMidYMid meet")
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Diverging bar chart");

          const inner = svg.append("g")
              .attr("class", "wt-diverging-inner")
              .attr("transform", `translate(0, ${paddingY})`);

          const tooltip = createChartTooltip();
          const tooltipHtml = (row) => {
              const header = row.tooltipLabel === "" ? row.label : row.tooltipLabel;
              const body = row.tooltip === "" ? row.value.toLocaleString() : row.tooltip;
              return (
                  `<strong>${escapeHtml(header)}</strong><br>`
                  + `<span class="wt-chart-tooltip__stat">${escapeHtml(body)}</span>`
              );
          };

          // ───── Centre rules — vertical hairlines flanking the
          // central label column, drawn full chart height so they
          // read as a continuous gutter regardless of row count.
          const rulesG = inner.append("g")
              .attr("class", "wt-diverging-rules");
          rulesG.append("line")
              .attr("class", "wt-diverging-rule")
              .attr("x1", centerLeftEdge)
              .attr("x2", centerLeftEdge)
              .attr("y1", 0)
              .attr("y2", rows.length * rowH)
              .style("stroke", "var(--border)")
              .style("stroke-width", "1");
          rulesG.append("line")
              .attr("class", "wt-diverging-rule")
              .attr("x1", centerRightEdge)
              .attr("x2", centerRightEdge)
              .attr("y1", 0)
              .attr("y2", rows.length * rowH)
              .style("stroke", "var(--border)")
              .style("stroke-width", "1");

          // ───── Bucket labels (centre column). The label shows the
          // bare range — direction (which side is older) is encoded by
          // the bar's column (left vs right) and spelled out by the
          // caption row beneath the chart, so a `+` / `−` prefix would
          // be redundant noise on top of `0-4` and ugly noise on top
          // of `30+`.
          const labelsG = inner.append("g")
              .attr("class", "wt-diverging-labels");
          labelsG.selectAll("text.wt-diverging-label")
              .data(rows)
              .enter()
              .append("text")
              .attr("class", "wt-diverging-label")
              .attr("x", centerX)
              .attr("y", (_d, i) => i * rowH + rowH / 2)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "middle")
              .style("fill", "var(--ink-2)")
              .style("font-family", "var(--mono)")
              .style("font-size", "11px")
              .text((d) => d.label);

          // ───── Left bars (sign === -1).
          const leftG = inner.append("g")
              .attr("class", "wt-diverging-bars-left");
          const leftRows = rows
              .map((row, i) => ({ row, i }))
              .filter(({ row }) => row.sign === -1 && row.value > 0);
          leftG.selectAll("rect.wt-diverging-bar-left")
              .data(leftRows)
              .enter()
              .append("rect")
              .attr("class", "wt-diverging-bar-left")
              .attr("x", ({ row }) => leftValueAnchorX - valueTextWidth - barWidthFor(row.value))
              .attr("y", ({ i }) => i * rowH + (rowH - barH) / 2)
              .attr("width", ({ row }) => barWidthFor(row.value))
              .attr("height", barH)
              .attr("rx", barRadius)
              .attr("ry", barRadius)
              .on("mouseover", (event, { row }) => tooltip.show(event, tooltipHtml(row)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());
          leftG.selectAll("text.wt-diverging-val-left")
              .data(leftRows)
              .enter()
              .append("text")
              .attr("class", "wt-diverging-val-left")
              .attr("x", leftValueAnchorX)
              .attr("y", ({ i }) => i * rowH + rowH / 2)
              .attr("text-anchor", "end")
              .attr("dominant-baseline", "middle")
              .style("fill", "var(--ink)")
              .style("font-family", "var(--mono)")
              .style("font-size", "12px")
              .text(({ row }) => row.value.toLocaleString());

          // ───── Right bars (sign === +1).
          const rightG = inner.append("g")
              .attr("class", "wt-diverging-bars-right");
          const rightRows = rows
              .map((row, i) => ({ row, i }))
              .filter(({ row }) => row.sign === 1 && row.value > 0);
          rightG.selectAll("rect.wt-diverging-bar-right")
              .data(rightRows)
              .enter()
              .append("rect")
              .attr("class", "wt-diverging-bar-right")
              .attr("x", () => rightValueAnchorX + valueTextWidth)
              .attr("y", ({ i }) => i * rowH + (rowH - barH) / 2)
              .attr("width", ({ row }) => barWidthFor(row.value))
              .attr("height", barH)
              .attr("rx", barRadius)
              .attr("ry", barRadius)
              .on("mouseover", (event, { row }) => tooltip.show(event, tooltipHtml(row)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());
          rightG.selectAll("text.wt-diverging-val-right")
              .data(rightRows)
              .enter()
              .append("text")
              .attr("class", "wt-diverging-val-right")
              .attr("x", rightValueAnchorX)
              .attr("y", ({ i }) => i * rowH + rowH / 2)
              .attr("text-anchor", "start")
              .attr("dominant-baseline", "middle")
              .style("fill", "var(--ink)")
              .style("font-family", "var(--mono)")
              .style("font-size", "12px")
              .text(({ row }) => row.value.toLocaleString());

          return svg.node();
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-diverging-bar, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$5(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * @param {unknown} value
   * @param {number}  defaultValue
   *
   * @returns {number}
   */
  function pickFraction$1(value, defaultValue) {
      if (typeof value !== "number" || !Number.isFinite(value)) {
          return defaultValue;
      }
      if (value < 0) {
          return 0;
      }
      if (value > 0.95) {
          return 0.95;
      }
      return value;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * D3-powered donut chart with one <path> per data row, caller-controlled
   * CSS classes, and native <title> tooltips. Sizes to the smaller of
   * width/height so the donut stays square inside a rectangular container.
   *
   * Empty/null/undefined data, all-zero values, and rows whose values are
   * non-finite or negative all render the shared empty-state placeholder
   * (after coercion). Redraw replaces both prior svg and prior placeholder
   * so the widget is idempotent in either direction.
   *
   * Fill is applied via `.style` rather than `.attr` so the data-supplied
   * value overrides any CSS rule for the slice class.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class DonutChart extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     holeSize?: number,
       *     margin?: number,
       *     width?: number,
       *     height?: number,
       *     centerLabel?: string,
       *     centerValue?: string,
       *     emptyMessage?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          const { width, height } = this.dimensions({ width: 250, height: 250 });
          this._side = Math.min(width, height);
          this._margin = pickPositive$4(this.options.margin, 1);
          this._radius = Math.max(0, (this._side >> 1) - this._margin);
          this._holeSize = pickHoleSize(this.options.holeSize, this._radius);
          this._centerLabel = typeof this.options.centerLabel === "string" ? this.options.centerLabel : "";
          this._centerValue = typeof this.options.centerValue === "string" ? this.options.centerValue : "";
      }

      /**
       * @param {Array<{label: string, value: number, class?: string, fill?: string}>|null|undefined} data
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const safeRows = sanitizeRows$4(data);
          const total = safeRows.reduce((acc, row) => acc + row.value, 0);

          if (safeRows.length === 0 || total <= 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const arc$1 = arc().innerRadius(this._holeSize).outerRadius(this._radius);

          const pie$1 = pie()
              .padAngle(1 / Math.max(this._radius, 1))
              .sort(null)
              .value((row) => row.value);

          const svg = select(this.target)
              .append("svg")
              .attr("class", "donut-chart")
              .attr("width", this._side)
              .attr("height", this._side)
              .attr("viewBox", `${-this._side / 2} ${-this._side / 2} ${this._side} ${this._side}`)
              .attr("style", "max-width: 100%; height: auto;");

          const slices = svg
              .append("g")
              .selectAll("path")
              .data(pie$1(safeRows))
              .join("path")
              .attr("class", (d) => (d.data.class ? `slice ${d.data.class}` : "slice"));

          slices.each(function (d) {
              if (d.data.fill !== undefined && d.data.fill !== null) {
                  this.style.fill = d.data.fill;
              }
          });

          // Grow each slice from zero sweep to its final angle for a
          // quick on-load animation. Initialise `_current` to the
          // start-angle pair so the interpolator has a stable origin.
          slices
              .each(function setInitialAngle(d) {
                  this._current = { startAngle: d.startAngle, endAngle: d.startAngle };
              })
              .transition("donut-enter")
              .duration(600)
              .ease(cubicOut)
              .attrTween("d", function tweenSlice(d) {
                  const interp = interpolate(this._current, d);
                  this._current = d;
                  return (t) => arc$1(interp(t));
              });

          const tooltip = createChartTooltip();
          const tooltipHtml = (row) => {
              const value = row.value || 0;
              const share = total > 0 ? (value / total) * 100 : 0;
              const shareLabel = share.toLocaleString(undefined, {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
              });
              const header = typeof row.tooltipLabel === "string" && row.tooltipLabel !== ""
                  ? row.tooltipLabel
                  : row.label;
              const body = typeof row.tooltipBody === "string" && row.tooltipBody !== ""
                  ? row.tooltipBody
                  : value.toLocaleString();
              const bodyWithShare = total > 0 ? `${body} · ${shareLabel}%` : body;
              return (
                  `<strong>${escapeHtml(header)}</strong><br>` +
                  `<span class="wt-chart-tooltip__stat">${escapeHtml(bodyWithShare)}</span>`
              );
          };

          slices
              .on("mouseover", (event, d) => tooltip.show(event, tooltipHtml(d.data)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          // Click → toggle selection. The predicate carries the
          // slice label so the dashboard-bus consumer can derive
          // whatever filter shape it needs. The d3-selection is
          // cached so `setSelection` (called by the bus when a
          // sibling widget emits) can re-apply highlight styles
          // without rebuilding the chart.
          this._slices = slices;
          const self = this;
          slices
              .attr("tabindex", "0")
              .style("cursor", "pointer")
              .on("click", function onClick(_event, d) {
                  const { predicate } = self._emitSelection({ slice: d.data.label });
                  self._applySelection(predicate);
              });

          // Centre value + label (optional). Rendered last so they
          // paint above the slices. The value is the larger serif
          // headline, the label a small uppercased caption underneath
          // — mirrors the design2 `.gs-donut-value` / `.gs-donut-
          // label` pair.
          const fallbackValue = this._centerValue === "" ? total.toLocaleString() : this._centerValue;
          svg.append("text")
              .attr("class", "donut-center-value")
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "middle")
              .attr("y", this._centerLabel === "" ? 0 : -8)
              .style("fill", "var(--ink)")
              .style("font-family", "var(--serif)")
              .style("font-size", "30px")
              .text(fallbackValue);

          if (this._centerLabel !== "") {
              svg.append("text")
                  .attr("class", "donut-center-label")
                  .attr("text-anchor", "middle")
                  .attr("dominant-baseline", "middle")
                  .attr("y", 18)
                  .style("fill", "var(--ink-2)")
                  .style("font-family", "var(--sans)")
                  .style("font-size", "10px")
                  .style("letter-spacing", "0.14em")
                  .style("text-transform", "uppercase")
                  .text(this._centerLabel);
          }

          return svg.node();
      }

      /**
       * Remove any svg and any placeholder this widget rendered earlier so
       * redraw() never stacks or leaves cross-state remnants.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.donut-chart, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * Toggle the `.is-selected` class on whichever slice matches
       * the current predicate; cleared selection removes the class
       * from every slice. The widget never sets inline opacity for
       * the selection state — dimming is entirely a host-stylesheet
       * concern, which keeps the click visual consistent with the
       * existing hover-dim CSS pattern (typically a `:has(.is-selected)
       * :not(.is-selected)` rule mirroring the `:hover` selectors).
       *
       * Recognised predicate shape: `{slice: <label>}`. A predicate
       * without `slice` (e.g. one emitted by a sibling widget on a
       * dimension this donut doesn't carry) clears the highlight so
       * the donut never displays a stale selection from an unrelated
       * click.
       *
       * @param {object|null} predicate
       * @returns {void}
       */
      _applySelection(predicate) {
          const slices = this._slices;
          if (slices === undefined || slices === null) {
              return;
          }
          if (predicate === null || typeof predicate !== "object" || !("slice" in predicate)) {
              slices.classed("is-selected", false);
              return;
          }
          slices.classed("is-selected", (d) => d.data.label === predicate.slice);
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * Coerce raw data into a clean array of `{label, value, …}` rows.
   * Drops rows that are not plain objects or whose value is non-finite
   * or negative (treated as 0 by callers means "skip").
   *
   * @param {unknown} data
   * @returns {Array<{label: string, value: number, class?: string, fill?: string}>}
   */
  function sanitizeRows$4(data) {
      if (!Array.isArray(data)) {
          return [];
      }
      const out = [];
      for (const row of data) {
          if (row === null || typeof row !== "object") {
              continue;
          }
          const value = Number.isFinite(row.value) && row.value > 0 ? row.value : 0;
          out.push({
              ...row,
              label: typeof row.label === "string" ? row.label : String(row.label ?? ""),
              value,
          });
      }
      return out.filter((row) => row.value > 0);
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   * @returns {number}
   */
  function pickPositive$4(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * Hole size accepts 0 (= pie chart), so the guard differs from pickPositive.
   * Negative / NaN / Infinity / strings fall back to the donut default.
   *
   * @param {unknown} value
   * @param {number}  radius
   * @returns {number}
   */
  function pickHoleSize(value, radius) {
      if (typeof value === "number" && Number.isFinite(value) && value >= 0) {
          return value;
      }
      return radius - radius / 10;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * Semicircle gauge — a single rounded-cap stroke whose dash length
   * encodes a percentage value (0–100). Track (unfilled portion)
   * paints `--border-soft` so the silhouette stays visible at 0 %.
   *
   * The arc is a top-half semicircle SVG path stroked at 14 px with
   * `stroke-linecap=round` so both ends land on smooth caps — direct
   * port of the design2 `<GaugeArc>` React widget.
   *
   * Below the arc sits the headline `value%` rendered as serif 56 px
   * with an italic ink-2 `%` suffix. Consumer templates render extra
   * captions (eyebrow label, mono meta, muted caption) as sibling
   * DOM elements via the GaugeArc partial.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class GaugeArc extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     value?: number,
       *     accent?: string,
       *     emptyMessage?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._accent = typeof this.options.accent === "string" && this.options.accent !== ""
              ? this.options.accent
              : "currentColor";
      }

      /**
       * @param {{value: number}|number|null|undefined} data Percentage 0–100, either as a scalar or wrapped in `{value: N}`.
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const value = sanitizeValue(data);

          if (value === null) {
              return this.renderEmptyState(this._emptyMessage());
          }

          // Design2 default `size = 200`, viewBox `size × size*0.62`,
          // radius `size/2 - 14`. Strokes are 14 px with rounded caps;
          // unfilled portion stays visible via the cream track painted
          // first.
          const SIZE = 200;
          const W = SIZE;
          const H = Math.round(SIZE * 0.62);
          const r = SIZE / 2 - 14;
          const cx = SIZE / 2;
          const cy = SIZE / 2 + 10;
          const arcPath = `M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`;
          const circumference = Math.PI * r;
          const filledFraction = Math.max(0, Math.min(1, value / 100));
          const dashLen = filledFraction * circumference;

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stat-gauge")
              .attr("viewBox", `0 0 ${W} ${H}`)
              .attr("preserveAspectRatio", "xMidYMid meet")
              .attr("role", "img");

          svg.append("path")
              .attr("d", arcPath)
              .attr("fill", "none")
              .attr("stroke", "var(--border-soft)")
              .attr("stroke-width", "14")
              .attr("stroke-linecap", "round");

          svg.append("path")
              .attr("d", arcPath)
              .attr("fill", "none")
              .attr("stroke", this._accent)
              .attr("stroke-width", "14")
              .attr("stroke-linecap", "round")
              .attr("stroke-dasharray", `${dashLen} ${circumference}`);

          // Headline `value%` centred over the arc baseline. Serif
          // 56 px value (mirrors design2 .gs-gauge-val), italic 24 px
          // ink-2 `%` suffix that recedes from the bignum read.
          // Eyebrow label ("documented" / "Lacy 1989") + mono meta
          // ("326 of 2,156") live OUTSIDE the SVG as sibling DOM
          // (see GaugeArc.phtml).
          const valueText = svg.append("text")
              .attr("x", cx)
              .attr("y", cy - 4)
              .attr("text-anchor", "middle")
              .attr("class", "wt-stat-gauge-val")
              .attr("fill", "var(--ink)")
              .style("font-family", "var(--serif)")
              .style("font-size", "56px")
              .style("letter-spacing", "-0.02em");
          valueText.append("tspan").text(formatValue(value));
          valueText.append("tspan")
              .attr("class", "wt-stat-gauge-suf")
              .attr("fill", "var(--ink-2)")
              .style("font-family", "var(--serif)")
              .style("font-size", "24px")
              .style("font-style", "italic")
              .style("letter-spacing", "0")
              .text("%");

          return svg.node();
      }

      /** @private */
      _clearChart() {
          select(this.target).selectAll("svg.wt-stat-gauge").remove();
      }

      /** @private */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string" && this.options.emptyMessage !== ""
              ? this.options.emptyMessage
              : "";
      }
  }

  /**
   * Coerce the input to a percentage in [0, 100]. Accepts a bare
   * number or a `{value: N}` wrapper (matches the data-payload shape
   * the partial emits).
   *
   * @param {{value: number}|number|null|undefined} data
   * @returns {number|null}
   */
  function sanitizeValue(data) {
      if (data === null || data === undefined) {
          return null;
      }
      let raw;
      if (typeof data === "number") {
          raw = data;
      } else if (typeof data === "object" && data !== null) {
          raw = Number(data.value);
      } else {
          raw = Number(data);
      }
      if (!Number.isFinite(raw)) {
          return null;
      }
      return Math.max(0, Math.min(100, raw));
  }

  /**
   * One-decimal localised number, falling back to integer when the
   * fractional digit is zero so "100%" doesn't read as "100.0%".
   *
   * @param {number} value
   * @returns {string}
   */
  function formatValue(value) {
      const rounded = Math.round(value * 10) / 10;
      return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS$2 = {
      height: 240,
      margin: { top: 12, right: 24, bottom: 32, left: 40 },
      showArea: true,
      xLabelEvery: 1,
  };

  /**
   * Line chart over a categorical x-axis. Payload mirrors the
   * {@see StackedBar} shape one level deep — same `{categories,
   * series}` top-level keys — but per series LineChart reads
   * `series[i].values: number[]` where StackedBar reads
   * `series[i].data: number[]`. A consumer that wants to swap
   * widget types renames that one field; everything else carries
   * over.
   *
   * Every series renders one path; tooltips surface the full
   * series-by-series value list at the hovered category.
   *
   * Single-series callers pass `series` with exactly one entry —
   * the area-under-line fill stays on (typical "growth" visual),
   * the legend is suppressed. Multi-series callers pass two or
   * more entries — the area fill is suppressed (visually noisy
   * when stacked) and a legend strip lands below the chart.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class LineChart extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     margin?: {top: number, right: number, bottom: number, left: number},
       *     showArea?: boolean,
       *     xLabelEvery?: number,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive$3(this.options.height, DEFAULT_OPTIONS$2.height);
          this._margin = { ...DEFAULT_OPTIONS$2.margin, ...(this.options.margin ?? {}) };
          this._showArea =
              typeof this.options.showArea === "boolean"
                  ? this.options.showArea
                  : DEFAULT_OPTIONS$2.showArea;
          this._xLabelEvery = Math.max(
              1,
              Math.floor(pickPositive$3(this.options.xLabelEvery, DEFAULT_OPTIONS$2.xLabelEvery)),
          );
      }

      /**
       * @param {{
       *     categories: string[],
       *     series: Array<{
       *         name: string,
       *         values: number[],
       *         class?: string,
       *         tooltips?: string[],
       *         tooltipLabels?: string[]
       *     }>
       * }|null|undefined} data
       *   - `categories` is the x-axis label list in display order.
       *   - `series[i].values[j]` is the y value of series `i` at
       *     category `j` — the array length must match the
       *     categories list (missing trailing entries are treated
       *     as zero).
       *   - `series[i].class` is an optional CSS hook on the
       *     series group so consumer styling can override the
       *     palette colour.
       *   - `series[i].tooltips[j]` overrides the default value
       *     rendering inside the chart-lib tooltip (e.g. "4
       *     births" pre-pluralised at the PHP boundary).
       *   - `series[i].tooltipLabels[j]` overrides the bold
       *     header when present (e.g. the bare category "17th"
       *     becomes "17th century" in the tooltip).
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const validated = this._validate(data);
          if (validated === null) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const { categories, series } = validated;
          const isMultiSeries = series.length > 1;

          const height = this._height;
          // Multi-series renders a legend strip under the x-axis;
          // give it its own band by widening the bottom margin so
          // legend swatches don't overlap the tick labels.
          const legendBandHeight = 20;
          const margin = {
              ...this._margin,
              bottom: this._margin.bottom + (isMultiSeries ? legendBandHeight : 0),
          };
          const width = Math.max(
              240,
              pickPositive$3(this.options.width, this.target.clientWidth) || 600,
          );
          const innerWidth = width - margin.left - margin.right;
          const innerHeight = height - margin.top - margin.bottom;

          const x = point$2().domain(categories).range([0, innerWidth]).padding(0.5);

          const yMax = max$3(series.flatMap((s) => s.values)) ?? 1;
          const y = linear$1().domain([0, yMax]).nice().range([innerHeight, 0]);

          const colour = ordinal()
              .domain(series.map((s) => s.name))
              .range(schemeTableau10);

          const tooltip = createChartTooltip();

          const svg = select(this.target)
              .append("svg")
              .attr("class", isMultiSeries ? "wt-line-chart wt-line-chart--multi" : "wt-line-chart")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Line chart");

          const inner = svg.append("g").attr("transform", `translate(${margin.left}, ${margin.top})`);

          // X-axis: show every Nth tick so dense series stay readable.
          const xLabelEvery = this._xLabelEvery;
          const xAxis = axisBottom(x).tickFormat((label, index) =>
              index % xLabelEvery === 0 ? label : "",
          );
          inner
              .append("g")
              .attr("class", "x-axis")
              .attr("transform", `translate(0, ${innerHeight})`)
              .call(xAxis);

          // Y-axis: integer-friendly ticks. `tickSize(-innerWidth)`
          // extends each tick mark across the plot area, turning the
          // axis into a gridline strip; CSS picks the dashed style
          // up from `.y-axis .tick line`.
          const yAxis = axisLeft(y)
              .ticks(5)
              .tickSize(-innerWidth)
              .tickPadding(8)
              .tickFormat((value) => Number(value).toLocaleString());
          inner.append("g").attr("class", "y-axis y-axis--grid").call(yAxis);

          const lineGenerator = line()
              .x((point) => x(point.label) ?? 0)
              .y((point) => y(point.value))
              .curve(monotoneX);

          // Single-series gets an area fill under the line ("growth"
          // visual); multi-series suppresses it to avoid visual
          // noise when bands overlap.
          const showArea = this._showArea && !isMultiSeries;
          const areaGenerator = area()
              .x((point) => x(point.label) ?? 0)
              .y0(innerHeight)
              .y1((point) => y(point.value))
              .curve(monotoneX);

          const seriesGroups = inner
              .append("g")
              .attr("class", "series-lines")
              .selectAll("g.series")
              .data(series)
              .enter()
              .append("g")
              .attr("class", (s) => (s.class === "" ? "series" : `series ${s.class}`))
              .attr("data-series-name", (s) => s.name);

          if (showArea) {
              seriesGroups
                  .append("path")
                  .datum((s) => this._materialisePoints(s, categories))
                  .attr("class", "area")
                  .attr("d", areaGenerator)
                  .attr("opacity", 0)
                  .transition("line-enter")
                  .duration(500)
                  .ease(cubicOut)
                  .attr("opacity", 0.25);
          }

          seriesGroups
              .append("path")
              .datum((s) => this._materialisePoints(s, categories))
              .attr("class", "line")
              .style("fill", "none")
              .style("stroke", function () {
                  // Single-series and class-themed series both let
                  // CSS own the stroke colour: an inline `style`
                  // would otherwise win over `.line` /
                  // `.series.<class> .line` rules. Only multi-series
                  // without a class token fall back to the ordinal
                  // scale.
                  const parent = this.parentNode;
                  if (!isMultiSeries) {
                      return null;
                  }
                  if (parent !== null && parent.classList.length > 1) {
                      return null;
                  }
                  const name = parent?.getAttribute("data-series-name") ?? "";
                  return colour(name) ?? "";
              })
              .attr("d", lineGenerator)
              .attr("stroke-dasharray", function () {
                  // jsdom does not implement getTotalLength; fall
                  // back to a no-op dasharray so the path still
                  // renders in the test environment.
                  return typeof this.getTotalLength === "function" ? this.getTotalLength() : 0;
              })
              .attr("stroke-dashoffset", function () {
                  return typeof this.getTotalLength === "function" ? this.getTotalLength() : 0;
              })
              .transition("line-enter")
              .duration(600)
              .ease(cubicOut)
              .attr("stroke-dashoffset", 0);

          // Hit-target circles per data point.
          seriesGroups
              .selectAll("circle.point")
              .data((s) => this._materialisePoints(s, categories))
              .enter()
              .append("circle")
              .attr("class", "point")
              .attr("cx", (point) => x(point.label) ?? 0)
              .attr("cy", (point) => y(point.value))
              .attr("r", 3)
              .attr("tabindex", "0")
              .attr("aria-label", (point) => `${point.label}: ${point.value.toLocaleString()}`)
              .on("mouseover", (event, point) => {
                  const header = point.tooltipLabel === "" ? point.label : point.tooltipLabel;
                  if (isMultiSeries) {
                      // Multi-series tooltip: one row per series at
                      // the hovered category.
                      const rows = series
                          .map((s) => {
                              const index = categories.indexOf(point.label);
                              const v = s.values[index] ?? 0;
                              return `<span class="wt-chart-tooltip__row">${escapeHtml(s.name)}: ${escapeHtml(v.toLocaleString())}</span>`;
                          })
                          .join("<br>");
                      tooltip.show(event, `<strong>${escapeHtml(header)}</strong><br>${rows}`);
                      return;
                  }
                  // Single-series: prefer the per-point tooltip
                  // override when supplied, otherwise the bare
                  // value.
                  const body =
                      point.tooltip === ""
                          ? escapeHtml(point.value.toLocaleString())
                          : escapeHtml(point.tooltip);
                  tooltip.show(
                      event,
                      `<strong>${escapeHtml(header)}</strong><br>` +
                          `<span class="wt-chart-tooltip__stat">${body}</span>`,
                  );
              })
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          if (isMultiSeries) {
              this._renderLegend(svg, series, colour, width, height, margin);
          }

          return svg.node();
      }

      /**
       * Validate the input payload into a normalised
       * `{categories, series}` shape, or return null to signal the
       * empty-state path.
       *
       * @param {unknown} data
       *
       * @returns {{categories: string[], series: Array<{name: string, values: number[], class: string, tooltips: string[], tooltipLabels: string[]}>}|null}
       */
      _validate(data) {
          if (data === null || data === undefined || typeof data !== "object") {
              return null;
          }
          const categories = Array.isArray(data.categories)
              ? data.categories
                    .filter((label) => typeof label === "string" && label !== "")
                    .map((label) => String(label))
              : [];
          const rawSeries = Array.isArray(data.series) ? data.series : [];

          if (categories.length === 0 || rawSeries.length === 0) {
              return null;
          }

          const series = rawSeries
              .filter((s) => s !== null && typeof s === "object" && Array.isArray(s.values))
              .map((s) => ({
                  name: String(s.name ?? ""),
                  class: typeof s.class === "string" ? s.class : "",
                  values: categories.map((_, index) => {
                      const value = Number(s.values[index] ?? 0);
                      return Number.isFinite(value) && value >= 0 ? value : 0;
                  }),
                  tooltips: Array.isArray(s.tooltips)
                      ? categories.map((_, index) =>
                            typeof s.tooltips[index] === "string" ? s.tooltips[index] : "",
                        )
                      : categories.map(() => ""),
                  tooltipLabels: Array.isArray(s.tooltipLabels)
                      ? categories.map((_, index) =>
                            typeof s.tooltipLabels[index] === "string" ? s.tooltipLabels[index] : "",
                        )
                      : categories.map(() => ""),
              }))
              .filter((s) => s.name !== "");

          if (series.length === 0) {
              return null;
          }

          const anyValue = series.some((s) => s.values.some((value) => value > 0));
          if (!anyValue) {
              return null;
          }

          return { categories, series };
      }

      /**
       * Inflate a single series into a list of point objects keyed
       * by category label, ready for d3-shape's line/area generators.
       *
       * @param {{name: string, values: number[], tooltips: string[], tooltipLabels: string[]}} s
       * @param {string[]} categories
       *
       * @returns {Array<{label: string, value: number, tooltip: string, tooltipLabel: string}>}
       */
      _materialisePoints(s, categories) {
          return categories.map((label, index) => ({
              label,
              value: s.values[index] ?? 0,
              tooltip: s.tooltips[index] ?? "",
              tooltipLabel: s.tooltipLabels[index] ?? "",
          }));
      }

      /**
       * Compact legend below the chart for multi-series payloads.
       * Each entry gets a colour swatch plus the series name.
       *
       * @param {import("d3-selection").Selection<SVGSVGElement, unknown, null, undefined>} svg
       * @param {Array<{name: string, class: string}>} series
       * @param {import("d3-scale").ScaleOrdinal<string, string>} colour
       * @param {number} width
       * @param {number} height
       * @param {{top: number, right: number, bottom: number, left: number}} margin
       */
      _renderLegend(svg, series, colour, width, height, margin) {
          const legend = svg.append("g").attr("class", "line-legend");
          const swatchSize = 10;
          const labelGap = 4;
          const itemSpacing = 16;
          const rowHeight = swatchSize + 4;
          let xOffset = margin.left;
          let yOffset = height - 4;

          for (const s of series) {
              const group = legend.append("g").attr("transform", `translate(${xOffset}, ${yOffset})`);
              const swatch = group
                  .append("rect")
                  .attr("class", `legend-swatch${s.class === "" ? "" : ` ${s.class}`}`)
                  .attr("width", swatchSize)
                  .attr("height", swatchSize)
                  .attr("y", -swatchSize);
              // Class-themed swatches let CSS pick the fill so the
              // legend stays in sync with the line colour.
              if (s.class === "") {
                  swatch.style("fill", colour(s.name) ?? "");
              }
              group
                  .append("text")
                  .attr("class", "legend-label")
                  .attr("x", swatchSize + labelGap)
                  .attr("y", -swatchSize / 2)
                  .attr("dominant-baseline", "middle")
                  .text(s.name);
              // Approximate text-advance via 7 px / character; same
              // best-effort heuristic as StackedBar. Host stylesheets
              // can tighten with `letter-spacing` if the result is
              // too sparse.
              const labelWidth = swatchSize + labelGap + s.name.length * 7;
              xOffset += labelWidth + itemSpacing;
              if (xOffset > width - margin.right) {
                  xOffset = margin.left;
                  yOffset += rowHeight;
              }
          }
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-line-chart, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$3(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * Mirror histogram — two histograms stacked vertically, the bottom
   * one flipped so the shared x-axis runs through the centre. Used for
   * paired distributions where the visual symmetry carries meaning:
   * husband / wife marriage age, father / mother age at first child,
   * husband / wife age at divorce.
   *
   * Both series share a single y-scale (peak count across BOTH sides)
   * so the bar lengths are directly comparable. The category axis sits
   * between the two histograms with the bucket labels printed once.
   *
   * Native `<title>` per bar gives the hover count without a tooltip
   * lifecycle.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class MirrorHistogram extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     width?: number,
       *     height?: number,
       *     topLabel?: string,
       *     bottomLabel?: string,
       *     emptyMessage?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          // Default height 440 ≈ design2 reference: 22 px top side-label
          // + 180 px top bars + 30 px axis strip + 180 px bottom bars
          // + 22 px bottom side-label. Per-side bar drawable area scales
          // linearly with the supplied height.
          const { width, height } = this.dimensions({ width: 720, height: 440 });
          this._width = width;
          this._height = height;
          this._topLabel = typeof this.options.topLabel === "string" ? this.options.topLabel : "";
          this._bottomLabel = typeof this.options.bottomLabel === "string" ? this.options.bottomLabel : "";
          // Bar + side-label colours are driven by CSS via per-side
          // class hooks (`wt-stat-mirror-bar-top` / `-bot`,
          // `wt-stat-mirror-axislabel-top` / `-bot`). Consumers theme
          // the widget through their own stylesheet — no per-instance
          // colour option survives to JavaScript.
      }

      /**
       * @param {{top: Array<{label: string, value: number}>, bottom: Array<{label: string, value: number}>}|null|undefined} data
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const top = sanitize(data?.top);
          const bottom = sanitize(data?.bottom);

          if (top.length === 0 && bottom.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          // Align the two series on their shared label set, preserving
          // the top series' order. Missing buckets on either side render
          // as zero-height bars so the axis stays continuous.
          const labels = top.map((row) => row.label);
          const bottomByLabel = new Map(bottom.map((row) => [row.label, row.value]));

          const W = this._width;
          const H = this._height;

          // Axis strip = 34.5 px tall band centred vertically (design
          // reference). `.gs-mirror-axis { padding: 8px 4px; border-top
          // + border-bottom: 1px solid var(--border); background:
          // var(--paper) }` lands at ≈ 34.5 px in the live design once
          // the label box (11 px font × 1.4 line-height = 15.4 px) plus
          // 8 px padding × 2 + 2 × 1 px borders adds up.
          const axisStripeHalf = 17.25;
          const axisCenter = H / 2;
          const axisTopY = axisCenter - axisStripeHalf;
          const axisBotY = axisCenter + axisStripeHalf;

          const maxValue = max$3([
              max$3(top, (d) => d.value) || 0,
              max$3(bottom, (d) => d.value) || 0,
          ]) || 1;

          // Lateral padding 4 px mirrors design2 `.gs-mirror-bars
          // { padding: 0 4px }`; paddingInner 0.28 puts a visible
          // gap (≈ design's `gap: 6px` between cols) between the bars.
          const x = band()
              .domain(labels)
              .range([4, W - 4])
              .paddingInner(0.28)
              .paddingOuter(0.05);

          // Reserved space per side, from svg edge inward:
          //   • 14 px side label (font 10 + descender + 4 px padding)
          //   • 16 px visual gap from side label to value text cap
          //   •  8 px value text glyph height (cap → baseline)
          //   •  4 px value text bottom → bar
          // = 42 px total. Max bar height = axisTopY - 42 leaves
          // identical gaps for top and bottom max bars regardless of
          // which side carries the larger value.
          const maxBarHeight = axisTopY - 42;
          const y = linear$1()
              .domain([0, maxValue])
              .range([0, maxBarHeight]);

          // Cap each bar at 48 px wide (mirrors design2 `.gs-mirror-bar
          // { max-width: 48px }`) and centre it within its band so wide
          // cards don't render block-thick columns when the bucket
          // count is low.
          const MAX_BAR_WIDTH = 48;
          const barWidth = Math.min(x.bandwidth(), MAX_BAR_WIDTH);
          const inset = (x.bandwidth() - barWidth) / 2;
          const barRadius = 4;

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stat-mirror")
              .attr("viewBox", `0 0 ${W} ${H}`)
              .attr("preserveAspectRatio", "xMidYMid meet")
              .attr("role", "img");

          // Side labels are anchored to the svg's vertical edges (top
          // and bottom), like CSS `position: absolute; top/bottom`. The
          // chart content lives in an inner `<g>` that vertically
          // re-centres itself between them so the largest top-bar and
          // the largest bottom-bar end up at IDENTICAL visual gaps to
          // their respective side label — even when the two series have
          // different max values.
          svg.append("text")
              .attr("x", 8)
              .attr("y", 14)
              .attr("class", "wt-stat-mirror-axislabel wt-stat-mirror-axislabel-top")
              .style("font-family", "var(--sans)")
              .style("font-size", "10px")
              .style("font-weight", "600")
              .style("letter-spacing", "0.14em")
              .style("text-transform", "uppercase")
              .text(this._topLabel);

          svg.append("text")
              .attr("x", 8)
              .attr("y", H - 4)
              .attr("class", "wt-stat-mirror-axislabel wt-stat-mirror-axislabel-bot")
              .style("font-family", "var(--sans)")
              .style("font-size", "10px")
              .style("font-weight", "600")
              .style("letter-spacing", "0.14em")
              .style("text-transform", "uppercase")
              .text(this._bottomLabel);

          // Inner-group vertical re-centre. Natural bbox of the chart
          // runs from the top of the top-max-bar's value text
          // (axisTopY - y(maxTop) - 12) down to the descender of the
          // bot-max-bar's value text (axisBotY + y(maxBot) + 14). The
          // target midpoint sits halfway between the MEN-label's
          // descender (y=16) and the WOMEN-label's cap top (y=H-12).
          // Translating the inner group by (target - natural) leaves
          // identical gaps on both sides.
          const maxTopValue = max$3(top, (d) => d.value) || 0;
          const maxBotValue = max$3(bottom, (d) => d.value) || 0;
          const naturalTopEdge = axisTopY - y(maxTopValue) - 12;
          const naturalBotEdge = axisBotY + y(maxBotValue) + 14;
          const naturalMidpoint = (naturalTopEdge + naturalBotEdge) / 2;
          const targetMidpoint = (16 + (H - 12)) / 2;
          const innerTranslateY = targetMidpoint - naturalMidpoint;

          const inner = svg.append("g")
              .attr("class", "wt-stat-mirror-inner")
              .attr("transform", `translate(0, ${innerTranslateY})`);

          // ───── Axis strip ─────
          const axisG = inner.append("g")
              .attr("class", "wt-stat-mirror-axis");
          axisG.append("rect")
              .attr("class", "wt-stat-mirror-axis-fill")
              .attr("x", 0)
              .attr("y", axisTopY)
              .attr("width", W)
              .attr("height", axisStripeHalf * 2)
              .style("fill", "var(--paper)");
          axisG.append("line")
              .attr("class", "wt-stat-mirror-axis-rule")
              .attr("x1", 0)
              .attr("x2", W)
              .attr("y1", axisTopY)
              .attr("y2", axisTopY)
              .style("stroke", "var(--border)")
              .style("stroke-width", "1");
          axisG.append("line")
              .attr("class", "wt-stat-mirror-axis-rule")
              .attr("x1", 0)
              .attr("x2", W)
              .attr("y1", axisBotY)
              .attr("y2", axisBotY)
              .style("stroke", "var(--border)")
              .style("stroke-width", "1");

          // Min height applies to any non-zero value so design2's
          // `min-height: 1px` parity holds — extremely small counts
          // still produce a visible bar instead of disappearing into
          // the axis rule.
          const renderHeight = (raw) => (raw > 0 && raw < 1 ? 1 : raw);

          // Path builder for a top-anchored bar with rounded top
          // corners only. Value 0 collapses to a 1-px stub sitting on
          // the axis rule so empty buckets stay visible.
          const topRoundedBar = (xPos, width, heightPx) => {
              const baseY = axisTopY;
              const h = renderHeight(heightPx);
              if (h <= 0) {
                  return `M${xPos},${baseY - 1}H${xPos + width}V${baseY}H${xPos}Z`;
              }
              const r = Math.min(barRadius, width / 2, h);
              const yTop = baseY - h;
              return `M${xPos},${baseY}`
                  + `V${yTop + r}`
                  + `Q${xPos},${yTop} ${xPos + r},${yTop}`
                  + `H${xPos + width - r}`
                  + `Q${xPos + width},${yTop} ${xPos + width},${yTop + r}`
                  + `V${baseY}`
                  + `Z`;
          };

          // Path builder for a bottom-anchored (flipped) bar with
          // rounded bottom corners only.
          const botRoundedBar = (xPos, width, heightPx) => {
              const baseY = axisBotY;
              const heightPxNorm = renderHeight(heightPx);
              if (heightPxNorm <= 0) {
                  return `M${xPos},${baseY}H${xPos + width}V${baseY + 1}H${xPos}Z`;
              }
              const r = Math.min(barRadius, width / 2, heightPxNorm);
              const yBot = baseY + heightPxNorm;
              return `M${xPos},${baseY}`
                  + `H${xPos + width}`
                  + `V${yBot - r}`
                  + `Q${xPos + width},${yBot} ${xPos + width - r},${yBot}`
                  + `H${xPos + r}`
                  + `Q${xPos},${yBot} ${xPos},${yBot - r}`
                  + `Z`;
          };

          const tooltip = createChartTooltip();
          const tooltipHtml = (row) => {
              const header = typeof row.tooltipLabel === "string" && row.tooltipLabel !== ""
                  ? row.tooltipLabel
                  : row.label;
              const body = typeof row.tooltipBody === "string" && row.tooltipBody !== ""
                  ? row.tooltipBody
                  : row.value.toLocaleString();
              return (
                  `<strong>${escapeHtml(header)}</strong><br>`
                  + `<span class="wt-chart-tooltip__stat">${escapeHtml(body)}</span>`
              );
          };

          // Bucket labels centred between the two axis rules (inside
          // the axis group so they translate with the rules).
          axisG.selectAll("text.wt-stat-mirror-cat")
              .data(labels)
              .enter()
              .append("text")
              .attr("class", "wt-stat-mirror-cat")
              .attr("x", (label) => (x(label) ?? 0) + x.bandwidth() / 2)
              .attr("y", axisCenter)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "middle")
              .style("fill", "var(--ink-2)")
              .style("font-family", "var(--mono)")
              .style("font-size", "11px")
              .text((label) => label);

          // ───── Top bars + their value captions ─────
          const topG = inner.append("g")
              .attr("class", "wt-stat-mirror-bars-top");
          topG.selectAll("path.wt-stat-mirror-bar-top")
              .data(top)
              .enter()
              .append("path")
              .attr("class", "wt-stat-mirror-bar-top")
              .attr("d", (d) => topRoundedBar((x(d.label) ?? 0) + inset, barWidth, y(d.value)))
              .on("mouseover", (event, d) => tooltip.show(event, tooltipHtml(d)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          topG.selectAll("text.wt-stat-mirror-val-top")
              .data(top.filter((d) => d.value > 0))
              .enter()
              .append("text")
              .attr("class", "wt-stat-mirror-val-top")
              .attr("x", (d) => (x(d.label) ?? 0) + x.bandwidth() / 2)
              .attr("y", (d) => axisTopY - y(d.value) - 4)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "alphabetic")
              .style("fill", "var(--ink-2)")
              .style("font-family", "var(--mono)")
              .style("font-size", "10px")
              .text((d) => d.value);

          // ───── Bottom bars + their value captions ─────
          const bottomAligned = labels.map((label) => ({
              label,
              value: bottomByLabel.get(label) ?? 0,
          }));

          const botG = inner.append("g")
              .attr("class", "wt-stat-mirror-bars-bot");
          botG.selectAll("path.wt-stat-mirror-bar-bot")
              .data(bottomAligned)
              .enter()
              .append("path")
              .attr("class", "wt-stat-mirror-bar-bot")
              .attr("d", (d) => botRoundedBar((x(d.label) ?? 0) + inset, barWidth, y(d.value)))
              .on("mouseover", (event, d) => tooltip.show(event, tooltipHtml(d)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          botG.selectAll("text.wt-stat-mirror-val-bot")
              .data(bottomAligned.filter((d) => d.value > 0))
              .enter()
              .append("text")
              .attr("class", "wt-stat-mirror-val-bot")
              .attr("x", (d) => (x(d.label) ?? 0) + x.bandwidth() / 2)
              .attr("y", (d) => axisBotY + y(d.value) + 12)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "alphabetic")
              .style("fill", "var(--ink-2)")
              .style("font-family", "var(--mono)")
              .style("font-size", "10px")
              .text((d) => d.value);

          return svg.node();
      }

      /** @private */
      _clearChart() {
          select(this.target).selectAll("svg.wt-stat-mirror").remove();
      }

      /** @private */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string" && this.options.emptyMessage !== ""
              ? this.options.emptyMessage
              : "";
      }
  }

  /**
   * Filter out non-numeric / missing-label rows. Order preserved.
   *
   * @param {Array<{label: string, value: number}>|null|undefined} rows
   * @returns {Array<{label: string, value: number}>}
   */
  function sanitize(rows) {
      if (!Array.isArray(rows)) {
          return [];
      }

      const out = [];
      for (const row of rows) {
          if (row === null || typeof row !== "object") {
              continue;
          }
          const label = typeof row.label === "string" ? row.label : String(row.label ?? "");
          const value = Number(row.value);
          if (label === "" || !Number.isFinite(value) || value < 0) {
              continue;
          }
          out.push({ label, value });
      }
      return out;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEGREES_PER_SLICE = 360 / 12;
  const QUADRANT_ANGLES = [0, 90, 180, 270];

  /**
   * 12-slice radial clock chart. Each wedge represents one slot
   * (typically a month or a zodiac sign); the wedge's outward extension
   * encodes its value. A base inner + outer ring plus four quadrant
   * gridlines frame the chart, and the peak slot's label sits in the
   * centre.
   *
   * The widget renders pure SVG via d3 — no JS animation, no tooltip
   * lifecycle. Hover surfaces the raw count via a native `<title>`
   * element on each wedge.
   *
   * Empty / null / undefined data renders the shared empty-state
   * placeholder.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class MonthRadial extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     size?: number,
       *     accent?: string,
       *     centerLabel?: string,
       *     emptyMessage?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._size = Number.isFinite(this.options.size) && this.options.size > 0
              ? this.options.size
              : 260;
          this._accent = typeof this.options.accent === "string" && this.options.accent !== ""
              ? this.options.accent
              : "currentColor";
          this._centerLabel = typeof this.options.centerLabel === "string" && this.options.centerLabel !== ""
              ? this.options.centerLabel
              : "Peak";
      }

      /**
       * @param {Array<{label: string, value: number}>|null|undefined} data
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const safe = sanitizeRows$3(data);

          if (safe.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const pad = 56;
          const vb = this._size + pad * 2;
          const cx = this._size / 2 + pad;
          const cy = this._size / 2 + pad;
          const labelPad = 18;
          const rOuter = this._size / 2 - labelPad;
          const rInner = 48;

          const max = safe.reduce((m, d) => (d.value > m ? d.value : m), 0);
          const peak = safe.reduce((p, d) => (d.value > p.value ? d : p), safe[0]);

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stat-radial-svg")
              .attr("viewBox", `0 0 ${vb} ${vb}`)
              .attr("preserveAspectRatio", "xMidYMid meet")
              .attr("role", "img");

          // Base rings
          for (const r of [rOuter, rInner]) {
              svg.append("circle")
                  .attr("cx", cx)
                  .attr("cy", cy)
                  .attr("r", r)
                  .attr("fill", "none")
                  .style("stroke", "var(--border-soft)")
                  .attr("stroke-width", 1);
          }

          // Quadrant gridlines (season markers)
          for (const a of QUADRANT_ANGLES) {
              const p1 = polar(cx, cy, a, rInner);
              const p2 = polar(cx, cy, a, rOuter);
              svg.append("line")
                  .attr("x1", p1.x)
                  .attr("y1", p1.y)
                  .attr("x2", p2.x)
                  .attr("y2", p2.y)
                  .style("stroke", "var(--border-soft)");
          }

          // Slice wedges
          const sliceArc = arc().innerRadius(rInner);
          const accent = this._accent;
          const tooltip = createChartTooltip();

          svg.selectAll("path.wt-stat-radial-slice")
              .data(safe.slice(0, 12))
              .enter()
              .append("path")
              .attr("class", "wt-stat-radial-slice")
              .attr("transform", `translate(${cx}, ${cy})`)
              .attr("d", (d, i) => {
                  const a0 = (i * DEGREES_PER_SLICE) * (Math.PI / 180);
                  const a1 = ((i + 1) * DEGREES_PER_SLICE) * (Math.PI / 180);
                  const ext = rInner + (max ? (d.value / max) * (rOuter - rInner - 4) : 0);
                  return sliceArc({ startAngle: a0, endAngle: a1, outerRadius: ext, innerRadius: rInner });
              })
              .style("fill", accent)
              .style("opacity", 0.85)
              .style("cursor", "default")
              .on("mouseover", (event, d) => {
                  tooltip.show(
                      event,
                      `<strong>${escapeHtml(d.label)}</strong><br>` +
                          `<span class="wt-chart-tooltip__stat">${escapeHtml(d.value.toLocaleString())}</span>`,
                  );
              })
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseout", () => tooltip.hide());

          // Month / sign labels around the perimeter
          safe.slice(0, 12).forEach((d, i) => {
              const angle = i * DEGREES_PER_SLICE + DEGREES_PER_SLICE / 2;
              const { x, y } = polar(cx, cy, angle, rOuter + labelPad);
              const cosA = Math.cos(((angle - 90) * Math.PI) / 180);
              const anchor = cosA > 0.3 ? "start" : cosA < -0.3 ? "end" : "middle";

              svg.append("text")
                  .attr("x", x)
                  .attr("y", y)
                  .attr("text-anchor", anchor)
                  .attr("dominant-baseline", "middle")
                  .attr("class", "wt-stat-radial-lab")
                  .style("fill", "var(--ink-2)")
                  .text(d.label);
          });

          // Centre caption — two stacked lines vertically centred on (cx, cy).
          // Setting dominant-baseline=middle pins each line by its centre, then
          // the line-half offsets (±10) split the block evenly around the
          // donut's geometric centre.
          svg.append("text")
              .attr("x", cx)
              .attr("y", cy - 10)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "middle")
              .attr("class", "wt-stat-radial-center")
              .style("fill", "var(--ink)")
              .text(peak.label);

          svg.append("text")
              .attr("x", cx)
              .attr("y", cy + 10)
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "middle")
              .attr("class", "wt-stat-radial-sub")
              .style("fill", "var(--ink-2)")
              .text(this._centerLabel);

          return svg.node();
      }

      /** @private */
      _clearChart() {
          select(this.target).selectAll("svg.wt-stat-radial-svg").remove();
      }

      /** @private */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string" && this.options.emptyMessage !== ""
              ? this.options.emptyMessage
              : "";
      }
  }

  /**
   * Project a polar coordinate (angle in degrees, radius) onto Cartesian
   * (x, y) centred at (cx, cy). Angles use clock convention: 0° = top,
   * increasing clockwise.
   *
   * @param {number} cx
   * @param {number} cy
   * @param {number} angleDeg
   * @param {number} r
   * @returns {{x: number, y: number}}
   */
  function polar(cx, cy, angleDeg, r) {
      const rad = ((angleDeg - 90) * Math.PI) / 180;
      return { x: cx + Math.cos(rad) * r, y: cy + Math.sin(rad) * r };
  }

  /**
   * Filter out non-finite / non-positive rows. Order is preserved.
   *
   * @param {Array<{label: string, value: number}>|null|undefined} data
   * @returns {Array<{label: string, value: number}>}
   */
  function sanitizeRows$3(data) {
      if (!Array.isArray(data)) {
          return [];
      }

      const out = [];
      for (const row of data) {
          if (row === null || typeof row !== "object") {
              continue;
          }
          const label = typeof row.label === "string" ? row.label : String(row.label ?? "");
          const value = Number(row.value);
          if (label === "" || !Number.isFinite(value) || value < 0) {
              continue;
          }
          out.push({ label, value });
      }
      return out;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * Circle-pack name bubbles. Each entry is rendered as a circle whose
   * radius encodes its count (computed via `d3-hierarchy.pack()` so the
   * layout is collision-free), and whose fill colour mixes the accent
   * token with the surrounding card surface, intensity-scaled.
   *
   * Click on a bubble emits a `selectionChanged` event on the
   * dashboard bus when an owning dimension is bound (`options.dimension`);
   * clicking the same bubble again clears the selection.
   *
   * Empty / null / undefined data renders the shared empty-state
   * placeholder. Redraw replaces both prior svg and prior placeholder
   * so the widget is idempotent in either direction.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class NameBubbles extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     width?: number,
       *     height?: number,
       *     accent?: string,
       *     dimension?: string,
       *     source?: string,
       *     padding?: number,
       *     emptyMessage?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          // The viewBox is locked at the design2 reference 720×360
          // (2:1). The SVG scales responsively via
          // `preserveAspectRatio="xMidYMid meet"`, keeping the bubble
          // pack visually consistent across narrow span-4 cards and
          // wide span-12 cards alike.
          this._width = Number.isFinite(this.options.width) && this.options.width > 0
              ? this.options.width
              : 720;
          this._height = Number.isFinite(this.options.height) && this.options.height > 0
              ? this.options.height
              : 360;
          // Spiral aspect drives the horizontal/vertical bias of the
          // outward spiral. `> 1` stretches the spiral wider than tall,
          // so bubbles fan out left and right first (matching the
          // landscape aspect of the host card) instead of stacking
          // vertically and shrinking the rendered pack. Caller can
          // override; default is 2:1 to track the reference viewBox.
          this._spiralAspectX = Number.isFinite(this.options.spiralAspectX) && this.options.spiralAspectX > 0
              ? this.options.spiralAspectX
              : 1.75;
          this._spiralAspectY = Number.isFinite(this.options.spiralAspectY) && this.options.spiralAspectY > 0
              ? this.options.spiralAspectY
              : 1;
          // Fixed bubble-radius bounds — sqrt-scaled by count fraction
          // so a single dominant name doesn't dwarf the rest. The
          // largest bubble lands at `rMax` (= 110 → 220 px diameter),
          // the smallest at `rMin` (= 50 → 100 px diameter), everything
          // in between proportional to sqrt(value/max).
          this._rMin = Number.isFinite(this.options.rMin) && this.options.rMin > 0
              ? this.options.rMin
              : 50;
          this._rMax = Number.isFinite(this.options.rMax) && this.options.rMax > this._rMin
              ? this.options.rMax
              : 110;
          this._accent = typeof this.options.accent === "string" && this.options.accent !== ""
              ? this.options.accent
              : "currentColor";
          this._padding = Number.isFinite(this.options.padding) && this.options.padding >= 0
              ? this.options.padding
              : 8;
          this._dimension = typeof this.options.dimension === "string" ? this.options.dimension : "";
          this._source = typeof this.options.source === "string" && this.options.source !== ""
              ? this.options.source
              : (this._dimension === "" ? "" : `name-bubbles.${this._dimension}`);
      }

      /**
       * @param {Array<{label: string, value: number}>|null|undefined} data
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const safe = sanitizeRows$2(data);

          if (safe.length === 0) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const sorted = [...safe].sort((a, b) => b.value - a.value);
          const max = sorted[0].value;
          const radiusFor = (value) => this._rMin + Math.sqrt(value / max) * (this._rMax - this._rMin);

          const W = this._width;
          const H = this._height;
          const cx = W / 2;
          const cy = H / 2;
          const padding = this._padding;

          // Spiral-out placement, overlap-free. The biggest bubble sits
          // at the centre; every subsequent bubble walks an outward
          // spiral until it finds a slot that doesn't touch any prior
          // placement. The spiral has no upper bound — if the chosen
          // r-range plus the entry count exceed the initial 720×360
          // box, the spiral simply keeps growing outward, and the
          // final viewBox absorbs the new bounding box (see below).
          // This guarantees that bubbles never overlap, even when the
          // configured r-range produces a total area that the
          // reference box can't hold.
          const leaves = [];

          sorted.forEach((row, idx) => {
              const r = radiusFor(row.value);

              if (idx === 0) {
                  leaves.push({ data: row, r, x: cx, y: cy });
                  return;
              }

              let placedX = null;
              let placedY = null;

              // Each pack rotates by a random offset AND draws a
              // slightly randomised aspect ratio per call so
              // consecutive reloads don't produce the identical
              // layout. The aspect bias stays inside `[1.2 … 1.6]` so
              // the pack stays landscape-leaning (matching the card
              // proportions) but the next-largest bubbles aren't
              // forced into the same left/right slots every time —
              // sometimes they land top-right, sometimes bottom-left.
              const startAngle = Math.random() * 360;
              const aspectJitterX = this._spiralAspectX * (0.85 + Math.random() * 0.3);
              const aspectJitterY = this._spiralAspectY * (0.85 + Math.random() * 0.3);

              for (let radius = r + padding; placedX === null; radius += 3) {
                  const angleStep = Math.max(1.5, 360 / (radius * 0.5));
                  for (let theta = 0; theta < 360; theta += angleStep) {
                      const rad = ((theta + startAngle) * Math.PI) / 180;
                      // Elliptical spiral with a small per-call jitter:
                      // x stretches by `aspectJitterX`, y by
                      // `aspectJitterY`. The horizontal default
                      // (`spiralAspectX=1.75`) keeps the pack landscape,
                      // the ±15 % jitter spreads adjacent renders so
                      // the same data doesn't always pack into the
                      // same shape.
                      const x = cx + Math.cos(rad) * radius * aspectJitterX;
                      const y = cy + Math.sin(rad) * radius * aspectJitterY;

                      let overlap = false;
                      for (const placed of leaves) {
                          const dx = placed.x - x;
                          const dy = placed.y - y;
                          if (Math.hypot(dx, dy) < placed.r + r + padding) {
                              overlap = true;
                              break;
                          }
                      }

                      if (!overlap) {
                          placedX = x;
                          placedY = y;
                          break;
                      }
                  }
              }

              leaves.push({ data: row, r, x: placedX, y: placedY });
          });

          // Compute the actual bounding box of every placed bubble so
          // the viewBox tracks the outward spiral instead of clipping
          // overflowing bubbles at the initial 720×360 edge. The
          // horizontal margin is wider than the vertical one — the
          // pack is naturally landscape (`spiralAspect = 2:1`) and
          // benefits from a generous gutter on either side so the
          // outer bubbles don't kiss the card edge.
          const vbPadX = 60;
          const vbPadY = 16;
          let minX = Infinity;
          let minY = Infinity;
          let maxX = -Infinity;
          let maxY = -Infinity;

          for (const leaf of leaves) {
              if (leaf.x - leaf.r < minX) minX = leaf.x - leaf.r;
              if (leaf.y - leaf.r < minY) minY = leaf.y - leaf.r;
              if (leaf.x + leaf.r > maxX) maxX = leaf.x + leaf.r;
              if (leaf.y + leaf.r > maxY) maxY = leaf.y + leaf.r;
          }

          const vbX = minX - vbPadX;
          const vbY = minY - vbPadY;
          const vbW = (maxX - minX) + vbPadX * 2;
          const vbH = (maxY - minY) + vbPadY * 2;

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stat-bubble")
              .attr("viewBox", `${vbX} ${vbY} ${vbW} ${vbH}`)
              .attr("preserveAspectRatio", "xMidYMid meet")
              .attr("role", "img");

          const isClickable = this._dimension !== "";

          const nodeSel = svg
              .selectAll("g.wt-stat-bubble-g")
              .data(leaves)
              .enter()
              .append("g")
              .attr("class", "wt-stat-bubble-g")
              .attr("transform", (d) => `translate(${d.x},${d.y})`);

          nodeSel
              .append("title")
              .text((d) => `${d.data.label}: ${d.data.value}`);

          nodeSel
              .append("circle")
              .attr("r", (d) => d.r)
              // `.style()` not `.attr()` — the colour-mix value carries
              // the per-bubble intensity tint and must beat any
              // stylesheet rule a consumer drops on `.wt-stat-bubble
              // circle`.
              .style("fill", (d) => {
                  const intensity = d.data.value / (max || 1);
                  const pct = Math.round(28 + intensity * 64);
                  return `color-mix(in srgb, ${this._accent} ${pct}%, var(--card))`;
              });

          // Name + count as one vertically-centred block around the
          // bubble centre. Both <text> nodes live inside a per-bubble
          // <g class="wt-stat-bubble-label">; the texts are laid out
          // at symmetric y offsets first, then the whole group is
          // re-translated so its rendered bounding-box centre lands
          // exactly on the bubble centre. Using the post-render bbox
          // sidesteps the em-box vs visible-glyph centroid mismatch
          // that every heuristic offset (`dy`, lift ratios, …) gets
          // wrong for at least one font / glyph set.
          //
          // `font-family` / `font-size` / `font-weight` go through
          // `.style()`, not `.attr()`. CSS custom properties like
          // `var(--serif)` only resolve when the value is parsed as a
          // CSS property — as an SVG presentation attribute the
          // literal string `var(--serif)` survives unparsed and the
          // browser falls back to the user-agent default font.
          const blockGap = 8;

          const labelG = nodeSel
              .append("g")
              .attr("class", "wt-stat-bubble-label");

          labelG
              .append("text")
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "central")
              .attr("y", (d) => {
                  if (d.r <= 22) {
                      // Tiny bubbles — single row, sit on the centre.
                      return 0;
                  }
                  const countFs = fitCountFontSize(d.r, d.data.value);
                  return -(blockGap + countFs) / 2;
              })
              .style("font-family", "var(--serif)")
              .style("font-size", (d) => `${fitNameFontSize(d.r, d.data.label)}px`)
              .style("fill", (d) => bubbleTextFill(d.data.value, max))
              .text((d) => d.data.label);

          labelG
              .filter((d) => d.r > 22)
              .append("text")
              .attr("text-anchor", "middle")
              .attr("dominant-baseline", "central")
              .attr("y", (d) => {
                  const nameFs = fitNameFontSize(d.r, d.data.label);
                  return (blockGap + nameFs) / 2;
              })
              .style("font-family", "var(--mono)")
              .style("font-weight", "500")
              .style("font-size", (d) => `${fitCountFontSize(d.r, d.data.value)}px`)
              .style("fill", (d) => bubbleCountFill(d.data.value, max))
              .text((d) => d.data.value);

          // Recentre each label group so its rendered bbox midpoint
          // coincides with the bubble centre. `getBBox()` returns the
          // post-layout extent of every visible glyph, which already
          // accounts for ascender height, descender depth, and any
          // font-specific overshoot — anchoring to that box gives
          // pixel-perfect centring without per-font fudge factors.
          // jsdom returns zero-width bboxes, so the guard keeps unit
          // tests stable while the real browser sees the recentre.
          labelG.each(function () {
              const box = this.getBBox();

              if (box.width === 0 && box.height === 0) {
                  return;
              }

              const cx = box.x + (box.width / 2);
              const cy = box.y + (box.height / 2);
              this.setAttribute("transform", `translate(${-cx},${-cy})`);
          });

          if (isClickable) {
              nodeSel.style("cursor", "pointer");
              nodeSel.on("click", (_event, d) => {
                  const next = this._currentSelection && this._currentSelection.value === d.data.label
                      ? null
                      : { dimension: this._dimension, value: d.data.label };
                  this._setSelection(next, leaves, svg);
                  this._emit(next);
              });
          }

          // Reapply selection state (covers re-draws + bus echoes).
          this._applySelectionDim(svg);

          return svg.node();
      }

      /**
       * BaseWidget hook — called by the dispatcher on bus echoes from
       * sibling widgets. Re-applies the dim overlay without rebuilding
       * the bubble layout.
       */
      setSelection(predicate) {
          if (predicate === null || predicate === undefined) {
              this._currentSelection = null;
          } else if (typeof predicate === "object" && predicate.dimension === this._dimension) {
              this._currentSelection = predicate;
          } else {
              this._currentSelection = null;
          }

          const svg = select(this.target).select("svg.wt-stat-bubble");
          if (!svg.empty()) {
              this._applySelectionDim(svg);
          }
      }

      /** @private */
      _setSelection(next, _leaves, svg) {
          this._currentSelection = next;
          this._applySelectionDim(svg);
      }

      /** @private */
      _applySelectionDim(svg) {
          const sel = this._currentSelection;
          svg.selectAll("g.wt-stat-bubble-g")
              .attr("opacity", (d) => {
                  if (sel === null) {
                      return 1;
                  }
                  return sel.value === d.data.label ? 1 : 0.3;
              });
      }

      /** @private */
      _emit(predicate) {
          if (typeof this._selectionCallback !== "function") {
              return;
          }
          this._selectionCallback({ source: this._source, predicate });
      }

      /** @private */
      _clearChart() {
          select(this.target).selectAll("svg.wt-stat-bubble").remove();
      }

      /** @private */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string" && this.options.emptyMessage !== ""
              ? this.options.emptyMessage
              : "";
      }
  }

  /**
   * Filter out non-finite / non-positive rows so the pack layout sees
   * a clean monotonic input. Order is preserved.
   *
   * @param {Array<{label: string, value: number}>|null|undefined} data
   * @returns {Array<{label: string, value: number}>}
   */
  function sanitizeRows$2(data) {
      if (!Array.isArray(data)) {
          return [];
      }

      const out = [];
      for (const row of data) {
          if (row === null || typeof row !== "object") {
              continue;
          }
          const label = typeof row.label === "string" ? row.label : String(row.label ?? "");
          const value = Number(row.value);
          if (label === "" || !Number.isFinite(value) || value <= 0) {
              continue;
          }
          out.push({ label, value });
      }
      return out;
  }

  /**
   * Bubble label font size clamped to the bubble radius. Smallest 9 px,
   * largest 22 px so even the giant centre bubble doesn't grow unbound.
   *
   * @param {number} r
   * @returns {number}
   */
  function clampFontSize(r) {
      // Radius-based ceiling. The actual emitted size is further
      // clamped against the bubble's interior chord (`fitNameFontSize`
      // / `fitCountFontSize`) so long labels never overflow the
      // circle's edge.
      return Math.max(11, Math.min(r / 2.5, 36));
  }

  /**
   * Count caption font size — radius-based ceiling. Always paired
   * with the chord-based fit below so the count digit never spills
   * out of the bubble.
   *
   * @param {number} r
   * @returns {number}
   */
  function clampCountFontSize(r) {
      return Math.max(11, Math.min(r / 3, 28));
  }

  /**
   * Approximate average serif glyph width as a fraction of em. Used
   * by the chord-fit clamp so we don't have to ship a measurement
   * canvas just to pick a label size.
   */
  const SERIF_GLYPH_RATIO = 0.55;

  /**
   * Mono glyph ratio is wider — tabular-figure mono fonts ship a
   * uniform `0.6 em` per digit.
   */
  const MONO_GLYPH_RATIO = 0.6;

  /**
   * Pick the largest serif font size that still fits the bubble's
   * inner chord at the label baseline (roughly the bubble diameter
   * minus a 10 % margin). Returns the radius-ceiling clamp when the
   * label is short enough that the radius is the binding constraint.
   *
   * @param {number} r
   * @param {string} label
   * @returns {number}
   */
  function fitNameFontSize(r, label) {
      const chord = r * 2 * 0.85;
      const ceiling = clampFontSize(r);
      if (typeof label !== "string" || label.length === 0) {
          return ceiling;
      }
      const widthCap = chord / (label.length * SERIF_GLYPH_RATIO);
      return Math.max(11, Math.min(ceiling, widthCap));
  }

  /**
   * Same idea for the mono count caption — the chord cap is slightly
   * tighter (`0.8`) so the count never butts up against the bubble
   * edge.
   *
   * @param {number} r
   * @param {number} value
   * @returns {number}
   */
  function fitCountFontSize(r, value) {
      const chord = r * 2 * 0.8;
      const ceiling = clampCountFontSize(r);
      const digits = String(value).length || 1;
      const widthCap = chord / (digits * MONO_GLYPH_RATIO);
      return Math.max(11, Math.min(ceiling, widthCap));
  }

  /**
   * Label colour chosen by intensity — dark text on light bubbles,
   * light text on saturated bubbles.
   *
   * @param {number} value
   * @param {number} max
   * @returns {string}
   */
  function bubbleTextFill(value, max) {
      return value / (max || 1) > 0.45 ? "var(--card)" : "var(--ink)";
  }

  /**
   * Count caption colour matched to the bubble's intensity (one step
   * paler than the name label for visual hierarchy).
   *
   * @param {number} value
   * @param {number} max
   * @returns {string}
   */
  function bubbleCountFill(value, max) {
      return value / (max || 1) > 0.45 ? "var(--card-warm)" : "var(--ink-3)";
  }

  function justify(node, n) {
    return node.sourceLinks.length ? node.depth : n - 1;
  }

  function constant(x) {
    return function() {
      return x;
    };
  }

  function ascendingSourceBreadth(a, b) {
    return ascendingBreadth(a.source, b.source) || a.index - b.index;
  }

  function ascendingTargetBreadth(a, b) {
    return ascendingBreadth(a.target, b.target) || a.index - b.index;
  }

  function ascendingBreadth(a, b) {
    return a.y0 - b.y0;
  }

  function value(d) {
    return d.value;
  }

  function defaultId(d) {
    return d.index;
  }

  function defaultNodes(graph) {
    return graph.nodes;
  }

  function defaultLinks(graph) {
    return graph.links;
  }

  function find(nodeById, id) {
    const node = nodeById.get(id);
    if (!node) throw new Error("missing: " + id);
    return node;
  }

  function computeLinkBreadths({nodes}) {
    for (const node of nodes) {
      let y0 = node.y0;
      let y1 = y0;
      for (const link of node.sourceLinks) {
        link.y0 = y0 + link.width / 2;
        y0 += link.width;
      }
      for (const link of node.targetLinks) {
        link.y1 = y1 + link.width / 2;
        y1 += link.width;
      }
    }
  }

  function Sankey() {
    let x0 = 0, y0 = 0, x1 = 1, y1 = 1; // extent
    let dx = 24; // nodeWidth
    let dy = 8, py; // nodePadding
    let id = defaultId;
    let align = justify;
    let sort;
    let linkSort;
    let nodes = defaultNodes;
    let links = defaultLinks;
    let iterations = 6;

    function sankey() {
      const graph = {nodes: nodes.apply(null, arguments), links: links.apply(null, arguments)};
      computeNodeLinks(graph);
      computeNodeValues(graph);
      computeNodeDepths(graph);
      computeNodeHeights(graph);
      computeNodeBreadths(graph);
      computeLinkBreadths(graph);
      return graph;
    }

    sankey.update = function(graph) {
      computeLinkBreadths(graph);
      return graph;
    };

    sankey.nodeId = function(_) {
      return arguments.length ? (id = typeof _ === "function" ? _ : constant(_), sankey) : id;
    };

    sankey.nodeAlign = function(_) {
      return arguments.length ? (align = typeof _ === "function" ? _ : constant(_), sankey) : align;
    };

    sankey.nodeSort = function(_) {
      return arguments.length ? (sort = _, sankey) : sort;
    };

    sankey.nodeWidth = function(_) {
      return arguments.length ? (dx = +_, sankey) : dx;
    };

    sankey.nodePadding = function(_) {
      return arguments.length ? (dy = py = +_, sankey) : dy;
    };

    sankey.nodes = function(_) {
      return arguments.length ? (nodes = typeof _ === "function" ? _ : constant(_), sankey) : nodes;
    };

    sankey.links = function(_) {
      return arguments.length ? (links = typeof _ === "function" ? _ : constant(_), sankey) : links;
    };

    sankey.linkSort = function(_) {
      return arguments.length ? (linkSort = _, sankey) : linkSort;
    };

    sankey.size = function(_) {
      return arguments.length ? (x0 = y0 = 0, x1 = +_[0], y1 = +_[1], sankey) : [x1 - x0, y1 - y0];
    };

    sankey.extent = function(_) {
      return arguments.length ? (x0 = +_[0][0], x1 = +_[1][0], y0 = +_[0][1], y1 = +_[1][1], sankey) : [[x0, y0], [x1, y1]];
    };

    sankey.iterations = function(_) {
      return arguments.length ? (iterations = +_, sankey) : iterations;
    };

    function computeNodeLinks({nodes, links}) {
      for (const [i, node] of nodes.entries()) {
        node.index = i;
        node.sourceLinks = [];
        node.targetLinks = [];
      }
      const nodeById = new Map(nodes.map((d, i) => [id(d, i, nodes), d]));
      for (const [i, link] of links.entries()) {
        link.index = i;
        let {source, target} = link;
        if (typeof source !== "object") source = link.source = find(nodeById, source);
        if (typeof target !== "object") target = link.target = find(nodeById, target);
        source.sourceLinks.push(link);
        target.targetLinks.push(link);
      }
      if (linkSort != null) {
        for (const {sourceLinks, targetLinks} of nodes) {
          sourceLinks.sort(linkSort);
          targetLinks.sort(linkSort);
        }
      }
    }

    function computeNodeValues({nodes}) {
      for (const node of nodes) {
        node.value = node.fixedValue === undefined
            ? Math.max(sum$1(node.sourceLinks, value), sum$1(node.targetLinks, value))
            : node.fixedValue;
      }
    }

    function computeNodeDepths({nodes}) {
      const n = nodes.length;
      let current = new Set(nodes);
      let next = new Set;
      let x = 0;
      while (current.size) {
        for (const node of current) {
          node.depth = x;
          for (const {target} of node.sourceLinks) {
            next.add(target);
          }
        }
        if (++x > n) throw new Error("circular link");
        current = next;
        next = new Set;
      }
    }

    function computeNodeHeights({nodes}) {
      const n = nodes.length;
      let current = new Set(nodes);
      let next = new Set;
      let x = 0;
      while (current.size) {
        for (const node of current) {
          node.height = x;
          for (const {source} of node.targetLinks) {
            next.add(source);
          }
        }
        if (++x > n) throw new Error("circular link");
        current = next;
        next = new Set;
      }
    }

    function computeNodeLayers({nodes}) {
      const x = max$3(nodes, d => d.depth) + 1;
      const kx = (x1 - x0 - dx) / (x - 1);
      const columns = new Array(x);
      for (const node of nodes) {
        const i = Math.max(0, Math.min(x - 1, Math.floor(align.call(null, node, x))));
        node.layer = i;
        node.x0 = x0 + i * kx;
        node.x1 = node.x0 + dx;
        if (columns[i]) columns[i].push(node);
        else columns[i] = [node];
      }
      if (sort) for (const column of columns) {
        column.sort(sort);
      }
      return columns;
    }

    function initializeNodeBreadths(columns) {
      const ky = min$2(columns, c => (y1 - y0 - (c.length - 1) * py) / sum$1(c, value));
      for (const nodes of columns) {
        let y = y0;
        for (const node of nodes) {
          node.y0 = y;
          node.y1 = y + node.value * ky;
          y = node.y1 + py;
          for (const link of node.sourceLinks) {
            link.width = link.value * ky;
          }
        }
        y = (y1 - y + py) / (nodes.length + 1);
        for (let i = 0; i < nodes.length; ++i) {
          const node = nodes[i];
          node.y0 += y * (i + 1);
          node.y1 += y * (i + 1);
        }
        reorderLinks(nodes);
      }
    }

    function computeNodeBreadths(graph) {
      const columns = computeNodeLayers(graph);
      py = Math.min(dy, (y1 - y0) / (max$3(columns, c => c.length) - 1));
      initializeNodeBreadths(columns);
      for (let i = 0; i < iterations; ++i) {
        const alpha = Math.pow(0.99, i);
        const beta = Math.max(1 - alpha, (i + 1) / iterations);
        relaxRightToLeft(columns, alpha, beta);
        relaxLeftToRight(columns, alpha, beta);
      }
    }

    // Reposition each node based on its incoming (target) links.
    function relaxLeftToRight(columns, alpha, beta) {
      for (let i = 1, n = columns.length; i < n; ++i) {
        const column = columns[i];
        for (const target of column) {
          let y = 0;
          let w = 0;
          for (const {source, value} of target.targetLinks) {
            let v = value * (target.layer - source.layer);
            y += targetTop(source, target) * v;
            w += v;
          }
          if (!(w > 0)) continue;
          let dy = (y / w - target.y0) * alpha;
          target.y0 += dy;
          target.y1 += dy;
          reorderNodeLinks(target);
        }
        if (sort === undefined) column.sort(ascendingBreadth);
        resolveCollisions(column, beta);
      }
    }

    // Reposition each node based on its outgoing (source) links.
    function relaxRightToLeft(columns, alpha, beta) {
      for (let n = columns.length, i = n - 2; i >= 0; --i) {
        const column = columns[i];
        for (const source of column) {
          let y = 0;
          let w = 0;
          for (const {target, value} of source.sourceLinks) {
            let v = value * (target.layer - source.layer);
            y += sourceTop(source, target) * v;
            w += v;
          }
          if (!(w > 0)) continue;
          let dy = (y / w - source.y0) * alpha;
          source.y0 += dy;
          source.y1 += dy;
          reorderNodeLinks(source);
        }
        if (sort === undefined) column.sort(ascendingBreadth);
        resolveCollisions(column, beta);
      }
    }

    function resolveCollisions(nodes, alpha) {
      const i = nodes.length >> 1;
      const subject = nodes[i];
      resolveCollisionsBottomToTop(nodes, subject.y0 - py, i - 1, alpha);
      resolveCollisionsTopToBottom(nodes, subject.y1 + py, i + 1, alpha);
      resolveCollisionsBottomToTop(nodes, y1, nodes.length - 1, alpha);
      resolveCollisionsTopToBottom(nodes, y0, 0, alpha);
    }

    // Push any overlapping nodes down.
    function resolveCollisionsTopToBottom(nodes, y, i, alpha) {
      for (; i < nodes.length; ++i) {
        const node = nodes[i];
        const dy = (y - node.y0) * alpha;
        if (dy > 1e-6) node.y0 += dy, node.y1 += dy;
        y = node.y1 + py;
      }
    }

    // Push any overlapping nodes up.
    function resolveCollisionsBottomToTop(nodes, y, i, alpha) {
      for (; i >= 0; --i) {
        const node = nodes[i];
        const dy = (node.y1 - y) * alpha;
        if (dy > 1e-6) node.y0 -= dy, node.y1 -= dy;
        y = node.y0 - py;
      }
    }

    function reorderNodeLinks({sourceLinks, targetLinks}) {
      if (linkSort === undefined) {
        for (const {source: {sourceLinks}} of targetLinks) {
          sourceLinks.sort(ascendingTargetBreadth);
        }
        for (const {target: {targetLinks}} of sourceLinks) {
          targetLinks.sort(ascendingSourceBreadth);
        }
      }
    }

    function reorderLinks(nodes) {
      if (linkSort === undefined) {
        for (const {sourceLinks, targetLinks} of nodes) {
          sourceLinks.sort(ascendingTargetBreadth);
          targetLinks.sort(ascendingSourceBreadth);
        }
      }
    }

    // Returns the target.y0 that would produce an ideal link from source to target.
    function targetTop(source, target) {
      let y = source.y0 - (source.sourceLinks.length - 1) * py / 2;
      for (const {target: node, width} of source.sourceLinks) {
        if (node === target) break;
        y += width + py;
      }
      for (const {source: node, width} of target.targetLinks) {
        if (node === source) break;
        y -= width;
      }
      return y;
    }

    // Returns the source.y0 that would produce an ideal link from source to target.
    function sourceTop(source, target) {
      let y = target.y0 - (target.targetLinks.length - 1) * py / 2;
      for (const {source: node, width} of target.targetLinks) {
        if (node === source) break;
        y += width + py;
      }
      for (const {target: node, width} of source.sourceLinks) {
        if (node === target) break;
        y -= width;
      }
      return y;
    }

    return sankey;
  }

  function horizontalSource(d) {
    return [d.source.x1, d.y0];
  }

  function horizontalTarget(d) {
    return [d.target.x0, d.y1];
  }

  function sankeyLinkHorizontal() {
    return linkHorizontal()
        .source(horizontalSource)
        .target(horizontalTarget);
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS$1 = {
      height: 320,
      margin: { top: 8, right: 130, bottom: 8, left: 130 },
      nodeWidth: 14,
      nodePad: 10,
  };

  /**
   * Sankey diagram for directed weighted flows between two columns of
   * nodes (e.g. birth-country → death-country migration). The caller
   * is responsible for delivering a DAG payload — d3-sankey throws
   * "circular link" otherwise. For bipartite use-cases where a node
   * could appear on both ends, the caller splits the node set so
   * source-side and target-side nodes occupy disjoint index ranges;
   * this widget caches the cycle-failure case and renders the empty
   * state rather than letting the throw take down the consumer.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class SankeyFlow extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     margin?: {top: number, right: number, bottom: number, left: number},
       *     nodeWidth?: number,
       *     nodePad?: number,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive$2(this.options.height, DEFAULT_OPTIONS$1.height);
          this._margin = { ...DEFAULT_OPTIONS$1.margin, ...(this.options.margin ?? {}) };
          this._nodeWidth = pickPositive$2(this.options.nodeWidth, DEFAULT_OPTIONS$1.nodeWidth);
          this._nodePad = pickPositive$2(this.options.nodePad, DEFAULT_OPTIONS$1.nodePad);
      }

      /**
       * @param {{
       *     nodes: Array<{name: string}>,
       *     links: Array<{
       *         source: number,
       *         target: number,
       *         value: number,
       *         samples?: Array<{name: string, xref?: string}>
       *     }>
       * }|null|undefined} data
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          if (
              !data ||
              !Array.isArray(data.nodes) ||
              data.nodes.length === 0 ||
              !Array.isArray(data.links) ||
              data.links.length === 0
          ) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const height = this._height;
          const margin = this._margin;
          const width = Math.max(
              360,
              pickPositive$2(this.options.width, this.target.clientWidth) || 900,
          );
          const innerWidth = width - margin.left - margin.right;
          const innerHeight = height - margin.top - margin.bottom;

          const tooltip = createChartTooltip();

          const colour = ordinal()
              .domain(data.nodes.map((entry) => entry.name))
              .range(schemeTableau10);

          const sankeyLayout = Sankey()
              .nodeWidth(this._nodeWidth)
              .nodePadding(this._nodePad)
              .nodeAlign(justify)
              .extent([
                  [margin.left, margin.top],
                  [margin.left + innerWidth, margin.top + innerHeight],
              ]);

          // d3-sankey throws "circular link" the moment its input
          // resolves to a directed cycle. Treat that as "no usable
          // data" rather than letting the whole consumer break.
          let graph;
          try {
              graph = sankeyLayout({
                  nodes: data.nodes.map((entry) => ({ ...entry })),
                  links: data.links.map((link) => ({ ...link })),
              });
          } catch (_error) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-sankey")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Sankey flow");

          const linkPath = sankeyLinkHorizontal();

          const links = svg
              .append("g")
              .attr("class", "links")
              .selectAll("path.link")
              .data(graph.links)
              .enter()
              .append("path")
              .attr("class", "link")
              .attr("d", linkPath)
              .attr("fill", "none")
              .attr("stroke", (link) => colour(link.source.name))
              .attr("stroke-opacity", 0)
              .attr("stroke-width", 0)
              .attr("tabindex", "0")
              .attr(
                  "aria-label",
                  (link) => `${link.source.name} → ${link.target.name}: ${link.value}`,
              );

          links
              .transition("sankey-enter")
              .duration(900)
              .delay((_, index) => index * 40)
              .ease(cubicOut)
              .attr("stroke-opacity", 0.45)
              .attr("stroke-width", (link) => Math.max(1, link.width));

          const i18n = this.options.i18n ?? {};
          const linkValueLabel = (count) => {
              const template = count === 1
                  ? (i18n.totalSingular ?? "{count} individual")
                  : (i18n.totalPlural ?? "{count} individuals");
              return template.replace("{count}", String(count));
          };

          links
              .on("mouseover", (event, link) => {
                  const head =
                      `<strong>${escapeHtml(link.source.name)} → ${escapeHtml(link.target.name)}</strong><br>` +
                      `<span class="wt-chart-tooltip__stat">${escapeHtml(linkValueLabel(link.value))}</span>`;
                  const samples = Array.isArray(link.samples) ? link.samples : [];
                  const sampleList = samples
                      .filter((sample) => sample !== null && typeof sample === "object")
                      .map((sample) => escapeHtml(String(sample.name ?? "")))
                      .filter((name) => name !== "")
                      .join("<br>");
                  const body = sampleList
                      ? `${head}<div class="wt-chart-tooltip__meta">${sampleList}</div>`
                      : head;
                  tooltip.show(event, body);
              })
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          // Click → toggle selection on a link. Predicate carries
          // both endpoints so the dashboard-bus consumer can derive
          // either a node filter or an edge filter.
          const self = this;
          links.style("cursor", "pointer").on("click", function onClick(_event, link) {
              const { predicate } = self._emitSelection({
                  source: link.source.name,
                  target: link.target.name,
              });
              self._applyLinkSelectionStyles(links, predicate);
          });

          const nodes = svg
              .append("g")
              .attr("class", "nodes")
              .selectAll("g.node")
              .data(graph.nodes)
              .enter()
              .append("g")
              .attr("class", "node");

          nodes
              .append("rect")
              .attr("x", (entry) => entry.x0)
              .attr("y", (entry) => entry.y0)
              .attr("width", (entry) => Math.max(0, entry.x1 - entry.x0))
              .attr("height", (entry) => Math.max(0, entry.y1 - entry.y0))
              .attr("fill", (entry) => colour(entry.name))
              .attr("opacity", 0)
              .transition("sankey-nodes")
              .duration(600)
              .delay(450)
              .ease(cubicOut)
              .attr("opacity", 0.9);

          nodes
              .append("text")
              .attr("class", "node-label")
              .attr("x", (entry) => (entry.x0 < width / 2 ? entry.x1 + 6 : entry.x0 - 6))
              .attr("y", (entry) => (entry.y0 + entry.y1) / 2)
              .attr("dominant-baseline", "middle")
              .attr("text-anchor", (entry) => (entry.x0 < width / 2 ? "start" : "end"))
              .attr("opacity", 0)
              .text((entry) => entry.name)
              .transition("sankey-labels")
              .duration(600)
              .delay(600)
              .ease(cubicOut)
              .attr("opacity", 1);

          return svg.node();
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-sankey, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * Toggle the `.is-selected` class on whichever link matches
       * the current predicate's source/target pair; cleared
       * selection removes the class from every link. The widget
       * never sets inline stroke-opacity — dim is a host-stylesheet
       * concern via `:has(.is-selected) :not(.is-selected)` rules
       * mirroring the existing `:has(path.link:hover) path.link:not(:hover)`
       * hover-dim rule, so click + hover read identically.
       *
       * @param {import("d3-selection").Selection<SVGPathElement, {source: {name: string}, target: {name: string}}, SVGGElement, unknown>} links
       * @param {object|null} predicate
       */
      _applyLinkSelectionStyles(links, predicate) {
          if (predicate === null) {
              links.classed("is-selected", false);
              return;
          }
          // Visual dim of non-selected links is a host-stylesheet
          // concern via `:has(.is-selected) :not(.is-selected)`,
          // mirroring the existing `:has(path.link:hover) path.link:not(:hover)`
          // hover-dim rule.
          links.classed(
              "is-selected",
              (link) =>
                  link.source.name === predicate.source && link.target.name === predicate.target,
          );
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$2(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_OPTIONS = {
      height: 280,
      margin: { top: 12, right: 24, bottom: 32, left: 48 },
      barPadding: 0.2,
      legend: true,
      percentage: false,
  };

  /**
   * Stacked bar chart for compositional payloads. Each category
   * carries a stack of series-keyed values that sum to the bar
   * height; the layout uses d3-shape's `stack()` so segment
   * ordering matches the order series arrive in.
   *
   * Tooltip surfaces both the hovered segment's value AND the
   * category's total, which is what the user actually wants to
   * see when comparing across categories ("4 divorces in 1900s
   * for ages 20-29, 27 divorces total in 1900s").
   *
   * Per-series colour comes from the `series[i].class` field when
   * provided (CSS class hook), otherwise falls back to a small
   * categorical palette. Colour palette is not opinionated — the
   * caller is expected to layer their own design tokens via the
   * CSS class hook on hot paths.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class StackedBar extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     margin?: {top: number, right: number, bottom: number, left: number},
       *     barPadding?: number,
       *     legend?: boolean,
       *     percentage?: boolean,
       *     emptyMessage?: string,
       *     ariaLabel?: string
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive$1(this.options.height, DEFAULT_OPTIONS.height);
          this._margin = { ...DEFAULT_OPTIONS.margin, ...(this.options.margin ?? {}) };
          this._barPadding = pickFraction(this.options.barPadding, DEFAULT_OPTIONS.barPadding);
          this._legend =
              typeof this.options.legend === "boolean" ? this.options.legend : DEFAULT_OPTIONS.legend;
          this._percentage =
              typeof this.options.percentage === "boolean"
                  ? this.options.percentage
                  : DEFAULT_OPTIONS.percentage;
      }

      /**
       * @param {{
       *     categories: string[],
       *     tooltipLabels?: string[],
       *     series: Array<{name: string, data: number[], class?: string}>
       * }|null|undefined} data
       *   `categories` is the x-axis label list in display order;
       *   `tooltipLabels[i]` is the optional long-form header the
       *   tooltip displays for category `i` (defaults to
       *   `categories[i]`). `series[i].data[j]` is the value of
       *   series `i` for category `j`. Each series may carry a CSS
       *   `class` so the consumer can theme segments via the host
       *   stylesheet instead of mutating the widget's palette.
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          const validated = this._validate(data);
          if (validated === null) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const { categories, tooltipLabels, series } = validated;
          const width = Math.max(
              240,
              pickPositive$1(this.options.width, this.target.clientWidth) || 600,
          );
          // Pre-compute how many rows the legend will need at the
          // current width so the bottom margin reserves enough space
          // for every row. A fixed 20 px would clip the second + third
          // legend rows when the labels wrap on a narrow viewport
          // (Family-tab "Family size composition share" at 393 px:
          // "3 children" + "4 or more children" wrap to extra rows).
          const legendRows = this._legend ? this._countLegendRows(series, width, this._margin) : 0;
          const legendRowHeight = 14;
          const legendBandHeight = legendRows > 0 ? legendRows * legendRowHeight + 6 : 0;
          const height = this._height + Math.max(0, legendBandHeight - 20);
          const margin = {
              ...this._margin,
              bottom: this._margin.bottom + legendBandHeight,
          };
          const innerWidth = width - margin.left - margin.right;
          const innerHeight = height - margin.top - margin.bottom;

          // d3-shape's stack works off an array of row objects keyed
          // by series name; transpose `series[i].data[j]` into one
          // row per category.
          const rows = categories.map((label, index) => {
              const row = { label };
              for (const s of series) {
                  row[s.name] = Number(s.data[index] ?? 0);
              }
              return row;
          });

          const keys = series.map((s) => s.name);
          const totals = rows.map((row) =>
              keys.reduce((sum, key) => sum + (Number(row[key]) || 0), 0),
          );

          // In percentage mode each bar is normalised to sum to 100;
          // the layout stacks the share rather than the raw count, so
          // the visual encoding emphasises composition over magnitude.
          // Raw counts stay reachable through `rows` for tooltip/aria
          // copy, so the user still sees the underlying numbers.
          const layoutRows = this._percentage
              ? rows.map((row, index) => {
                    const total = totals[index];
                    if (total <= 0) {
                        return { ...row };
                    }
                    const scaled = { label: row.label };
                    for (const key of keys) {
                        scaled[key] = ((Number(row[key]) || 0) / total) * 100;
                    }
                    return scaled;
                })
              : rows;

          const stackLayout = stack().keys(keys)(layoutRows);
          const valueMax = this._percentage ? 100 : (max$3(totals) ?? 1);

          const x = band().domain(categories).range([0, innerWidth]).padding(this._barPadding);

          const y = linear$1().domain([0, valueMax]).nice().range([innerHeight, 0]);

          const colour = ordinal()
              .domain(keys)
              .range(
                  series.map((s, index) =>
                      typeof s.class === "string" && s.class !== ""
                          ? null
                          : schemeTableau10[index % schemeTableau10.length],
                  ),
              );

          const tooltip = createChartTooltip();

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stacked-bar")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Stacked bar chart");

          const inner = svg.append("g").attr("transform", `translate(${margin.left}, ${margin.top})`);

          // Thin x-axis labels when there are too many to fit
          // horizontally — pin .tickValues() to roughly every Nth
          // category so the axis stays readable on dense category
          // sets (e.g. 40+ decades). Mirrors the StreamGraph's
          // `.ticks(Math.min(rows.length, 8))` auto-thinning. The
          // tooltip still surfaces every category's value on hover,
          // so no category is lost — only the labels thin out.
          const targetTicks = 10;
          const tickStride = Math.max(1, Math.ceil(categories.length / targetTicks));
          const tickedAxis = axisBottom(x);
          if (tickStride > 1) {
              tickedAxis.tickValues(categories.filter((_, i) => i % tickStride === 0));
          }

          inner
              .append("g")
              .attr("class", "x-axis")
              .attr("transform", `translate(0, ${innerHeight})`)
              .call(tickedAxis);

          inner
              .append("g")
              .attr("class", "y-axis")
              .call(
                  axisLeft(y)
                      .ticks(5)
                      .tickFormat((value) =>
                          this._percentage
                              ? `${Number(value).toLocaleString()}%`
                              : Number(value).toLocaleString(),
                      ),
              );

          const seriesGroups = inner
              .append("g")
              .attr("class", "stacks")
              .selectAll("g.series")
              .data(stackLayout)
              .enter()
              .append("g")
              .attr("class", (_d, index) => {
                  const seriesEntry = series[index];
                  const cssClass =
                      typeof seriesEntry?.class === "string" && seriesEntry.class !== ""
                          ? ` ${seriesEntry.class}`
                          : "";
                  return `series${cssClass}`;
              })
              .attr("data-series-name", (_d, index) => series[index]?.name ?? "")
              .attr("fill", (d) => colour(d.key) ?? "");

          seriesGroups
              .selectAll("rect.segment")
              .data((d) => d)
              .enter()
              .append("rect")
              .attr("class", "segment")
              .attr("x", (segment) => x(segment.data.label) ?? 0)
              .attr("width", x.bandwidth())
              .attr("y", innerHeight)
              .attr("height", 0)
              .attr("tabindex", "0")
              .attr("aria-label", function (segment) {
                  const seriesNode = this.parentNode;
                  const seriesName = seriesNode?.getAttribute("data-series-name") ?? "";
                  const categoryIndex = categories.indexOf(segment.data.label);
                  const rawValue = Number(rows[categoryIndex]?.[seriesName]) || 0;
                  return `${segment.data.label} / ${seriesName}: ${rawValue.toLocaleString()}`;
              })
              .transition("stack-enter")
              .duration(500)
              .ease(cubicOut)
              .attr("y", (segment) => y(segment[1]))
              .attr("height", (segment) => y(segment[0]) - y(segment[1]));

          // Hover handlers re-bind from the parent so we can read the
          // series-name attribute the d3.attr() function above already
          // wrote — keeps the segment->series mapping local to the DOM.
          const widgetSelf = this;
          inner.selectAll("rect.segment").on("mouseover", function (event, segment) {
              const seriesName = this.parentNode?.getAttribute("data-series-name") ?? "";
              const categoryIndex = categories.indexOf(segment.data.label);
              const value = Number(rows[categoryIndex]?.[seriesName]) || 0;
              const total = totals[categoryIndex] ?? 0;
              const share = total > 0 ? Math.round((value / total) * 100) : 0;
              const header = tooltipLabels[categoryIndex] ?? segment.data.label;
              const totalCategoryTpl =
                  (widgetSelf.options?.i18n?.totalInCategoryPattern) ?? "{count} total in this category";
              tooltip.show(
                  event,
                  `<strong>${escapeHtml(header)}</strong><br>` +
                      `<span class="wt-chart-tooltip__row">${escapeHtml(seriesName)}: ${escapeHtml(value.toLocaleString())} (${share}%)</span><br>` +
                      `<span class="wt-chart-tooltip__sub">${escapeHtml(totalCategoryTpl.replace("{count}", total.toLocaleString()))}</span>`,
              );
          });

          inner
              .selectAll("rect.segment")
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide());

          if (this._legend) {
              this._renderLegend(svg, series, colour, width, height, margin, legendRows);
          }

          return svg.node();
      }

      /**
       * Validate the input payload into a normalised
       * `{categories, series}` shape, or return null to signal
       * the empty-state path.
       *
       * @param {unknown} data
       *
       * @returns {{categories: string[], tooltipLabels: string[], series: Array<{name: string, data: number[], class?: string}>}|null}
       */
      _validate(data) {
          if (data === null || data === undefined || typeof data !== "object") {
              return null;
          }
          const categories = Array.isArray(data.categories)
              ? data.categories.filter((label) => typeof label === "string" && label !== "")
              : [];
          const seriesIn = Array.isArray(data.series) ? data.series : [];

          if (categories.length === 0 || seriesIn.length === 0) {
              return null;
          }

          // `tooltipLabels` mirrors the LineChart contract: a parallel
          // array of long-form headers shown in the tooltip while the
          // shorter `categories` stay on the x-axis. Missing entries
          // fall back to the matching category so callers can opt in
          // per chart.
          const tooltipLabels = categories.map((label, index) => {
              const candidate = Array.isArray(data.tooltipLabels)
                  ? data.tooltipLabels[index]
                  : undefined;
              return typeof candidate === "string" && candidate !== "" ? candidate : label;
          });

          const series = seriesIn
              .filter((s) => s !== null && typeof s === "object" && Array.isArray(s.data))
              .map((s) => ({
                  name: String(s.name ?? ""),
                  class: typeof s.class === "string" ? s.class : "",
                  data: categories.map((_, index) => {
                      const value = Number(s.data[index] ?? 0);
                      return Number.isFinite(value) && value >= 0 ? value : 0;
                  }),
              }))
              .filter((s) => s.name !== "");

          if (series.length === 0) {
              return null;
          }

          const anyValue = series.some((s) => s.data.some((value) => value > 0));
          if (!anyValue) {
              return null;
          }

          return { categories, tooltipLabels, series };
      }

      /**
       * Render a compact legend below the chart. Each item carries
       * a colour swatch matching the corresponding series so the
       * stacking order remains discoverable without hovering.
       *
       * @param {import("d3-selection").Selection<SVGSVGElement, unknown, null, undefined>} svg
       * @param {Array<{name: string, class?: string}>} series
       * @param {import("d3-scale").ScaleOrdinal<string, string>} colour
       * @param {number} width
       * @param {number} height
       * @param {{top: number, right: number, bottom: number, left: number}} margin
       */
      /**
       * Predict how many rows the wrapping legend will use at the
       * supplied width. Shares the per-label width heuristic with
       * {@link _renderLegend} (7 px / char advance + swatch + gap)
       * so the reserved bottom band matches the rendered layout.
       *
       * @param {Array<{name: string}>} series
       * @param {number} width
       * @param {{left: number, right: number}} margin
       * @returns {number}
       */
      _countLegendRows(series, width, margin) {
          const swatchSize = 10;
          const labelGap = 4;
          const itemSpacing = 16;
          const wrapLimit = width - margin.right;
          let xOffset = margin.left;
          let rows = 1;

          for (const entry of series) {
              const labelWidth = swatchSize + labelGap + entry.name.length * 7;

              if (xOffset > margin.left && xOffset + labelWidth > wrapLimit) {
                  xOffset = margin.left;
                  rows += 1;
              }

              xOffset += labelWidth + itemSpacing;
          }

          return rows;
      }

      _renderLegend(svg, series, colour, width, height, margin, legendRows) {
          const legend = svg.append("g").attr("class", "stack-legend");
          const swatchSize = 10;
          const labelGap = 4;
          const itemSpacing = 16;
          const rowHeight = swatchSize + 4;
          let xOffset = margin.left;
          // Place the legend in the reserved bottom band — below the
          // x-axis tick labels rather than above the chart. The
          // `-swatchSize / 2` shifts the swatch's vertical centre to
          // the band's centreline so the labels and swatches share
          // a single optical baseline. For multi-row legends the
          // FIRST row needs to start `(rows - 1) * rowHeight` higher
          // up so the LAST row still lands on the same bottom
          // baseline as a single-row legend.
          const totalRows = Math.max(1, legendRows ?? 1);
          let yOffset = height - 4 - swatchSize / 2 - (totalRows - 1) * rowHeight;
          const wrapLimit = width - margin.right;

          for (const entry of series) {
              // Approximate text width: SVG cannot measure text without
              // a DOM layout pass, so use a conservative 7 px / char
              // advance plus the swatch + gap. This is a best-effort
              // wrap heuristic; the host stylesheet can tighten the
              // legend with letter-spacing if the result is too sparse.
              const labelWidth = swatchSize + labelGap + entry.name.length * 7;

              // Wrap BEFORE drawing when the current item wouldn't
              // fit inside the legend band. The previous "increment
              // first, wrap next" rule placed the overflowing item on
              // the row that already lacked room for it, so its right
              // edge clipped at the SVG boundary on narrow viewports
              // (the Family-tab "Family size composition share" card
              // lost the "3 children" tail at 393 px). Skip the wrap
              // for the first item on a row to avoid the empty-leading-
              // wrap edge case when an oversized label still doesn't
              // fit even on its own line.
              if (xOffset > margin.left && xOffset + labelWidth > wrapLimit) {
                  xOffset = margin.left;
                  yOffset += rowHeight;
              }

              const group = legend.append("g").attr("transform", `translate(${xOffset}, ${yOffset})`);
              group
                  .append("rect")
                  .attr("class", `legend-swatch${entry.class === "" ? "" : ` ${entry.class}`}`)
                  .attr("width", swatchSize)
                  .attr("height", swatchSize)
                  .attr("y", -swatchSize / 2)
                  .attr("fill", colour(entry.name) ?? "");
              group
                  .append("text")
                  .attr("x", swatchSize + labelGap)
                  .attr("y", 0)
                  .attr("dominant-baseline", "middle")
                  .attr("class", "legend-label")
                  .text(entry.name);

              xOffset += labelWidth + itemSpacing;
          }
      }

      /**
       * Remove any svg + placeholder this widget rendered earlier so
       * redraw() never stacks.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-stacked-bar, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive$1(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * @param {unknown} value
   * @param {number}  defaultValue
   *
   * @returns {number}
   */
  function pickFraction(value, defaultValue) {
      if (typeof value !== "number" || !Number.isFinite(value)) {
          return defaultValue;
      }
      if (value < 0) {
          return 0;
      }
      if (value > 0.95) {
          return 0.95;
      }
      return value;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const DEFAULT_MARGIN = { top: 4, right: 16, bottom: 28, left: 16 };
  const DEFAULT_HEIGHT = 240;

  /**
   * Silhouette stream-graph showing per-decade frequencies of stacked
   * categorical bands (e.g. top-N given names across a tree). Each
   * band is one category; the band's vertical thickness in a column
   * shows that category's count for the decade.
   *
   * Empty/null/undefined data or a series without any names/decades
   * renders the shared empty-state placeholder via BaseWidget.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class StreamGraph extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     height?: number,
       *     width?: number,
       *     margin?: {top: number, right: number, bottom: number, left: number},
       *     emptyMessage?: string,
       *     ariaLabel?: string,
       *     i18n?: {
       *         decadeSuffix?: string,
       *         totalSingular?: string,
       *         totalPlural?: string,
       *         peakInPattern?: string,
       *         ariaBandPattern?: string
       *     }
       * }} [options]
       */
      constructor(target, options) {
          super(target, options);
          this._height = pickPositive(this.options.height, DEFAULT_HEIGHT);
          this._margin = { ...DEFAULT_MARGIN, ...(this.options.margin ?? {}) };
      }

      /**
       * @param {{
       *     decades: Array<number>,
       *     names:   Array<string>,
       *     series:  Object<string, Object<number, number>>
       * }|null|undefined} data
       *
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          if (
              !data ||
              !Array.isArray(data.decades) ||
              data.decades.length === 0 ||
              !Array.isArray(data.names) ||
              data.names.length === 0
          ) {
              return this.renderEmptyState(this._emptyMessage());
          }

          const height = this._height;
          const margin = this._margin;
          const width = Math.max(
              360,
              pickPositive(this.options.width, this.target.clientWidth) || 900,
          );
          const innerWidth = width - margin.left - margin.right;
          const innerHeight = height - margin.top - margin.bottom;

          // Transform into the dense row-per-decade shape d3.stack expects.
          const rows = data.decades.map((decade) => {
              const row = { decade };
              data.names.forEach((name) => {
                  row[name] = data.series[name]?.[decade] || 0;
              });
              return row;
          });

          const series = stack()
              .keys(data.names)
              .offset(stackOffsetSilhouette)
              .order(stackOrderInsideOut)(rows);

          const xScale = linear$1()
              .domain(extent(rows, (row) => row.decade))
              .range([0, innerWidth]);

          // Add a small headroom above + below the silhouette envelope
          // so the outermost bands don't touch the SVG edges.
          const yLower = min$2(series, (band) => min$2(band, (point) => point[0])) ?? 0;
          const yUpper = max$3(series, (band) => max$3(band, (point) => point[1])) ?? 0;
          const yPad = Math.max((yUpper - yLower) * 0.08, 1);
          const yScale = linear$1()
              .domain([yLower - yPad, yUpper + yPad])
              .range([innerHeight, 0]);

          const colour = ordinal().domain(data.names).range(schemeTableau10);

          const areaPath = area()
              .x((point) => xScale(point.data.decade))
              .y0((point) => yScale(point[0]))
              .y1((point) => yScale(point[1]))
              .curve(curveBasis);

          // Flat baseline path for the on-load animation.
          const yMid = yScale((yLower + yUpper) / 2);
          const flatPath = area()
              .x((point) => xScale(point.data.decade))
              .y0(yMid)
              .y1(yMid)
              .curve(curveBasis);

          const tooltip = createChartTooltip();

          const svg = select(this.target)
              .append("svg")
              .attr("class", "wt-stream-graph")
              .attr("viewBox", `0 0 ${width} ${height}`)
              .attr("role", "img")
              .attr("aria-label", this.options.ariaLabel ?? "Stream graph");

          // Centre inner content vertically inside the SVG. The bottom
          // margin holds the x-axis tick labels; without a top
          // counterpart the rendered <g> bounding box drifts downward
          // by half the asymmetry. A small upward shim brings the bbox
          // back to centre, derived from the margins so a future caller
          // that swaps in different margins still gets a centred chart.
          const verticalCentringShim = Math.round((margin.bottom - margin.top) / 2);
          const inner = svg
              .append("g")
              .attr("transform", `translate(${margin.left}, ${margin.top - verticalCentringShim})`);

          const bandTotals = new Map(
              series.map((band) => [
                  band.key,
                  band.reduce((sum, point) => sum + (point[1] - point[0]), 0),
              ]),
          );

          const peakDecade = (band) => {
              let bestDecade = band[0]?.data?.decade ?? null;
              let bestSize = -Infinity;
              band.forEach((point) => {
                  const size = point[1] - point[0];
                  if (size > bestSize) {
                      bestSize = size;
                      bestDecade = point.data.decade;
                  }
              });
              return bestDecade;
          };

          // i18n option pack — every string falls back to the canonical
          // English variant when the host doesn't override it. The patterns
          // use curly-brace placeholders ({count}, {decade}, {name}/{total}/
          // {peak}) rather than sprintf %s tokens because webtrees-core pipes
          // every msgid through sprintf, which would mangle bare %s.
          const i18n = this.options.i18n ?? {};
          const decadeSuffix = i18n.decadeSuffix ?? "s";
          const decadeFmt = (decade) => `${decade}${decadeSuffix}`;
          const totalLabel = (count) => {
              const template = count === 1
                  ? (i18n.totalSingular ?? "{count} individual")
                  : (i18n.totalPlural ?? "{count} individuals");
              return template.replace("{count}", String(count));
          };
          const peakLabel = (decade) => {
              const template = i18n.peakInPattern ?? "peak in the {decade}";
              return template.replace("{decade}", decadeFmt(decade));
          };

          const bands = inner
              .selectAll("path.band")
              .data(series)
              .enter()
              .append("path")
              .attr("class", "band")
              .attr("data-name", (band) => band.key)
              .attr("fill", (band) => colour(band.key))
              .attr("opacity", 0)
              .attr("d", flatPath)
              .attr("tabindex", "0")
              .attr("aria-label", (band) => {
                  const total = Math.round(bandTotals.get(band.key) ?? 0);
                  const ariaTpl = i18n.ariaBandPattern ?? "{name}: {total}, {peak}";
                  return ariaTpl
                      .replace("{name}", band.key)
                      .replace("{total}", totalLabel(total))
                      .replace("{peak}", peakLabel(peakDecade(band)));
              });

          bands
              .transition("stream-graph-enter")
              .duration(900)
              .delay((_, index) => index * 40)
              .ease(cubicOut)
              .attr("opacity", 0.85)
              .attr("d", areaPath);

          const bandTooltipHtml = (band) => {
              const total = Math.round(bandTotals.get(band.key) ?? 0);
              const peak = peakDecade(band);
              return (
                  `<strong>${escapeHtml(band.key)}</strong><br>` +
                  `<span class="wt-chart-tooltip__stat">${escapeHtml(totalLabel(total))}</span><br>` +
                  `<span class="wt-chart-tooltip__meta">${escapeHtml(peakLabel(peak))}</span>`
              );
          };

          bands
              .on("mouseover", (event, band) => tooltip.show(event, bandTooltipHtml(band)))
              .on("mousemove", (event) => tooltip.move(event))
              .on("mouseleave", () => tooltip.hide())
              .on("focus", (event, band) => {
                  // Keyboard focus has no cursor; pin to the band's top edge.
                  const bbox = event.target.getBoundingClientRect();
                  tooltip.show(
                      { clientX: bbox.left + bbox.width / 2, clientY: bbox.top + 12 },
                      bandTooltipHtml(band),
                  );
              })
              .on("blur", () => tooltip.hide());

          // Click → toggle selection on the band's series key. The
          // predicate's `name` matches StreamGraph's payload key so
          // dashboard-bus consumers can derive whatever filter shape
          // they need.
          const self = this;
          bands.style("cursor", "pointer").on("click", function onClick(_event, band) {
              const { predicate } = self._emitSelection({ name: band.key });
              self._applyStreamSelectionStyles(bands, predicate);
          });

          inner
              .append("g")
              .attr("class", "x-axis")
              .attr("transform", `translate(0, ${innerHeight})`)
              .call(
                  axisBottom(xScale)
                      .ticks(Math.min(rows.length, 8))
                      .tickFormat(decadeFmt),
              );

          // Hide the y axis: a stream graph reads as relative magnitudes;
          // absolute counts live in the band tooltips.
          inner.append("g").attr("class", "y-axis").call(axisLeft(yScale).ticks(0).tickSize(0));

          return svg.node();
      }

      /**
       * Remove any svg + empty-state placeholder this widget rendered
       * earlier so redraw() never stacks or leaves cross-state remnants.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.wt-stream-graph, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }

      /**
       * Toggle the `.is-selected` class on whichever band matches
       * the current predicate's series key; cleared selection
       * removes the class from every band. The widget never sets
       * inline opacity — dim is a host-stylesheet concern via
       * `:has(.is-selected) :not(.is-selected)` rules mirroring
       * the existing `:has(path.band:hover) path.band:not(:hover)`
       * hover-dim rule, so click + hover read identically.
       *
       * @param {import("d3-selection").Selection<SVGPathElement, {key: string}, SVGGElement, unknown>} bands
       * @param {object|null} predicate
       */
      _applyStreamSelectionStyles(bands, predicate) {
          if (predicate === null) {
              bands.classed("is-selected", false);
              return;
          }
          // Visual dim of non-selected bands is a host-stylesheet
          // concern via `:has(.is-selected) :not(.is-selected)`,
          // mirroring the existing `:has(path.band:hover) path.band:not(:hover)`
          // hover-dim rule.
          bands.classed("is-selected", (band) => band.key === predicate.name);
      }

      /**
       * @returns {string}
       */
      _emptyMessage() {
          return typeof this.options.emptyMessage === "string"
              ? this.options.emptyMessage
              : "No data available";
      }
  }

  /**
   * @param {unknown} value
   * @param {number}  fallback
   *
   * @returns {number}
   */
  function pickPositive(value, fallback) {
      return typeof value === "number" && Number.isFinite(value) && value > 0 ? value : fallback;
  }

  /**
   * This file is part of the package magicsunday/webtrees-chart-lib.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * D3-powered choropleth world map. Geojson is consumer-owned (not bundled).
   *
   * Data joins to features by case-insensitive ISO-3166-1 alpha-2, with the
   * row's countryCode trimmed before lookup so backend whitespace (NBSP,
   * leading/trailing spaces from CSV imports) does not silently drop rows.
   * Features without a matching row render with data-count="0" and a
   * neutral fill via the `--chart-empty-fill` CSS variable.
   *
   * Caller-overridable: projection (must implement d3-geo's fitSize) and
   * color scale (d3-scale-compatible). Bad geojson (missing FeatureCollection
   * type, non-object features, missing/non-string iso_a2) is filtered in
   * the constructor so render never aborts mid-flight after clearing target.
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-chart-lib/
   */
  class WorldMap extends BaseWidget {
      /**
       * @param {string|HTMLElement} target
       * @param {{
       *     geojson: object,
       *     projection?: {fitSize: Function},
       *     colorScale?: (value: number) => string,
       *     accent?: string,
       *     emptyMessage?: string,
       *     width?: number,
       *     height?: number
       * }} options
       */
      constructor(target, options) {
          super(target, options);

          const geojson = this.options.geojson;
          if (geojson === null || typeof geojson !== "object") {
              throw new Error(`${this.constructor.name}: options.geojson is required`);
          }
          if (geojson.type !== "FeatureCollection" || !Array.isArray(geojson.features)) {
              throw new Error(
                  `${this.constructor.name}: options.geojson must be a GeoJSON FeatureCollection`,
              );
          }
          if (
              this.options.projection !== undefined &&
              typeof this.options.projection?.fitSize !== "function"
          ) {
              throw new Error(`${this.constructor.name}: options.projection must implement fitSize`);
          }

          const { width, height } = this.dimensions({ width: 640, height: 320 });
          this._width = width;
          this._height = height;
          this._geojson = {
              ...geojson,
              features: geojson.features.filter(
                  (feature) => feature !== null && typeof feature === "object",
              ),
          };
      }

      /**
       * @param {Array<{countryCode: string, label?: string, count: number}>|null|undefined} data
       * @returns {SVGSVGElement|HTMLElement}
       */
      draw(data) {
          this._clearChart();

          // Unlike the other widgets, the map's geometry IS the
          // primary signal — readers expect to see the world even
          // when no records landed on it. Skip the empty-state
          // placeholder and render the map with every country on
          // `emptyFill` instead; that still distinguishes "no data
          // recorded" without hiding the chart.
          const rows = sanitizeRows(data);
          const byIso = new Map(rows.map((row) => [row.countryCode, row]));

          const projection = (this.options.projection ?? geoEquirectangular()).fitSize(
              [this._width, this._height],
              this._geojson,
          );
          const path = geoPath(projection);

          const colorDomain = extent(rows, (row) => row.count);
          const domain = colorDomain[0] === colorDomain[1] ? [0, colorDomain[1] || 1] : colorDomain;
          let color = this.options.colorScale;
          if (color === undefined) {
              // `accent` overrides the default blues palette with a
              // host-supplied colour. The scale fades countries from a
              // pale paper-toned start to the full accent at the
              // domain's top end so the Places-tab map stays in sync
              // with the tab pill + progress-list bars (sage / slate /
              // wine) instead of always painting blue. Falls back to
              // the d3-blues palette when no accent is supplied.
              //
              // `var(--token)` strings are resolved against the chart
              // host's computed style before being handed to
              // `interpolateRgb` — d3-interpolate can't follow CSS
              // custom properties on its own.
              const accentRaw = typeof this.options.accent === "string" && this.options.accent !== ""
                  ? this.options.accent
                  : null;
              const accent = accentRaw === null ? null : resolveCssColor(this.target, accentRaw);
              if (accent === null) {
                  color = sequential(interpolateBlues).domain(domain);
              } else {
                  // Start the scale at a pale-accent tint (15 % accent
                  // over white) so even the lowest-count country reads
                  // as the view's colour family rather than washed-out
                  // white. The high end stays at the full accent.
                  const palest = interpolateRgb("#ffffff", accent)(0.15);
                  color = sequential(interpolateRgb(palest, accent)).domain(domain);
              }
          }
          // Countries without any data stay on the neutral
          // `--chart-empty-fill` so the map still reads as "no record"
          // for those territories — the accent scale is reserved for
          // countries that contributed a count.
          const emptyFill = "var(--chart-empty-fill, #eee)";

          const svg = select(this.target)
              .append("svg")
              .attr("class", "world-map")
              .attr("width", this._width)
              .attr("height", this._height)
              .attr("viewBox", `0 0 ${this._width} ${this._height}`)
              .attr("style", "max-width: 100%; height: auto;");

          const countries = svg
              .append("g")
              .selectAll("path.country")
              .data(this._geojson.features)
              .join("path")
              .attr("class", "country")
              .attr("d", path)
              .attr("data-iso", (feature) => upperIso(feature))
              .attr("data-count", (feature) => String(byIso.get(upperIso(feature))?.count ?? 0));

          countries.each(/** @this {SVGPathElement} */ function (feature) {
              const row = byIso.get(upperIso(feature));
              this.style.fill = row ? color(row.count) : emptyFill;
          });

          const tooltip = createChartTooltip();

          const tooltipHtml = (feature, row) => {
              const iso = upperIso(feature);
              const label = row?.label ?? feature.properties?.name ?? iso;
              const count = row?.count ?? 0;
              return (
                  `<strong>${escapeHtml(String(label))}</strong><br>` +
                  `<span class="wt-chart-tooltip__stat">${count.toLocaleString()}</span>`
              );
          };

          countries
              .on("mouseover", (event, feature) => {
                  const row = byIso.get(upperIso(feature));
                  // Countries with no recorded data stay quiet — a tooltip
                  // showing "0 individuals" reads as noise on a Mercator
                  // covered in unused territories.
                  if (row === undefined) {
                      return;
                  }
                  tooltip.show(event, tooltipHtml(feature, row));
              })
              .on("mousemove", (event, feature) => {
                  if (byIso.get(upperIso(feature)) === undefined) {
                      return;
                  }
                  tooltip.move(event);
              })
              .on("mouseleave", () => tooltip.hide());

          return svg.node();
      }

      /**
       * Remove any svg and placeholder this widget rendered earlier so
       * redraw is idempotent in both directions.
       *
       * @returns {void}
       */
      _clearChart() {
          for (const node of this.target.querySelectorAll(
              ":scope > svg.world-map, :scope > .chart-empty-state",
          )) {
              node.remove();
          }
      }
  }

  /**
   * @param {unknown} data
   * @returns {Array<{countryCode: string, label?: string, count: number}>}
   */
  function sanitizeRows(data) {
      if (!Array.isArray(data)) {
          return [];
      }
      const out = [];
      for (const row of data) {
          if (row === null || typeof row !== "object") {
              continue;
          }
          if (typeof row.countryCode !== "string") {
              continue;
          }
          const code = row.countryCode.trim().toUpperCase();
          if (code.length === 0) {
              continue;
          }
          out.push({
              ...row,
              countryCode: code,
              count: Number.isFinite(row.count) ? row.count : 0,
          });
      }
      return out;
  }

  /**
   * Safe ISO accessor — coerces non-string iso_a2 (numeric sentinel values
   * like -99 emitted by some Natural Earth converters, null/undefined
   * properties, or null feature itself) into an uppercase string.
   *
   * @param {unknown} feature
   * @returns {string}
   */
  /**
   * Natural Earth ships a handful of features with `ISO_A2 = "-99"`
   * — France, Norway, Kosovo, N. Cyprus, Somaliland — because their
   * extended-hierarchy entries are split across multiple territories
   * and the public-domain dataset deliberately leaves the field
   * sentinel-valued. Fall back to the country name when the ISO field
   * is the "-99" sentinel so the choropleth still colours those
   * countries on a regular tree.
   */
  const NAME_TO_ISO2_FALLBACK = {
      france: "FR",
      norway: "NO",
      kosovo: "XK",
      "n. cyprus": "CY",
      "northern cyprus": "CY",
      somaliland: "SO",
  };

  /**
   * Resolve a CSS colour string against the host element's computed
   * style so d3-interpolate sees a concrete hex / rgb() value. Accepts
   * either `var(--token)` (extracted via getPropertyValue) or any plain
   * CSS colour (returned as-is). Falls back to the input when the
   * lookup yields an empty string (the host element isn't in the live
   * DOM yet during unit-test snapshots).
   *
   * @param {HTMLElement} host
   * @param {string} value
   * @returns {string}
   */
  function resolveCssColor(host, value) {
      const trimmed = value.trim();
      const match = /^var\(\s*(--[^,\s)]+)/.exec(trimmed);
      if (match === null) {
          return trimmed;
      }
      if (typeof window === "undefined" || typeof window.getComputedStyle !== "function") {
          return trimmed;
      }
      const resolved = window.getComputedStyle(host).getPropertyValue(match[1]).trim();
      return resolved === "" ? trimmed : resolved;
  }

  function upperIso(feature) {
      if (feature === null || typeof feature !== "object") {
          return "";
      }
      // Natural Earth GeoJSONs ship uppercase keys (`ISO_A2`, `ISO_A2_EH`),
      // hand-cleaned exports often switch to lowercase (`iso_a2`). Accept
      // whichever variant is present so the widget is compatible with the
      // common public GeoJSON sources without forcing the caller to
      // pre-transform their data.
      const props = feature.properties ?? {};
      const iso = props.iso_a2 ?? props.ISO_A2 ?? props.ISO_A2_EH ?? null;
      if (iso !== null && iso !== undefined && iso !== "-99") {
          return String(iso).toUpperCase();
      }

      // ISO sentinel — fall back to name lookup.
      const name = props.NAME ?? props.NAME_LONG ?? props.name ?? null;
      if (typeof name === "string") {
          const fallback = NAME_TO_ISO2_FALLBACK[name.toLowerCase()];
          if (fallback !== undefined) {
              return fallback;
          }
      }

      return "";
  }

  /**
   * This file is part of the package magicsunday/webtrees-statistics.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  /**
   * Shared selection bus for cross-widget filtering. Widgets emit a
   * `selectionChanged` event with a predicate describing the current
   * filter (or `null` to clear); the bus rebroadcasts to every
   * subscriber.
   *
   * The contract is intentionally minimal — the bus does not know
   * anything about the structure of the predicate, just that it's an
   * opaque value (or null). Each subscriber decides whether the
   * predicate is relevant to its own data and applies it (or
   * ignores it).
   *
   * @typedef {object} DashboardSelection
   * @property {string} source     Stable identifier of the widget that emitted the event (`"donut.births-century"`, `"name-bubbles.top-surnames"`, ...). Subscribers use this to ignore their own emissions.
   * @property {unknown} predicate Filter payload — shape is widget-specific. `null` means "clear filter".
   *
   * @author  Rico Sonntag <mail@ricosonntag.de>
   * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
   * @link    https://github.com/magicsunday/webtrees-statistics/
   */
  class DashboardBus {
      constructor() {
          this._dispatch = dispatch$1("selectionChanged");
      }

      /**
       * Broadcast a selection to every subscriber. Callers should
       * include a stable `source` identifier so subscribers can
       * ignore their own emissions.
       *
       * @param {DashboardSelection} selection
       *
       * @returns {void}
       */
      emit(selection) {
          this._dispatch.call("selectionChanged", null, selection);
      }

      /**
       * Subscribe to selection changes. Returns an `unsubscribe`
       * function so callers can disconnect cleanly when the host
       * widget is torn down.
       *
       * @param {(selection: DashboardSelection) => void} callback
       *
       * @returns {() => void}
       */
      onSelectionChanged(callback) {
          const namespace = `selectionChanged.sub-${Math.random().toString(36).slice(2)}`;
          this._dispatch.on(namespace, callback);

          return () => {
              this._dispatch.on(namespace, null);
          };
      }
  }

  /**
   * This file is part of the package magicsunday/webtrees-statistics.
   *
   * For the full copyright and license information, please read the
   * LICENSE file distributed with this source code.
   */


  const WORLD_GEOJSON_URL =
      "/index.php?route=%2Fmodule%2F_webtrees-statistics_%2FAsset&asset=js/world-map.geojson";

  let cachedGeoJson = null;

  /**
   * Lazily load (and cache) the world GeoJSON. The chart-lib WorldMap
   * widget needs the FeatureCollection up-front in its options; we
   * fetch it once per page load and reuse for every map render.
   *
   * @returns {Promise<object>} The parsed FeatureCollection.
   */
  async function loadWorldGeoJson() {
      if (cachedGeoJson === null) {
          const raw = await json(WORLD_GEOJSON_URL);
          // Drop Antarctica — no genealogy data is going to land in
          // AQ and the continent eats roughly a third of a Mercator
          // projection's vertical space, squishing every populated
          // landmass.
          cachedGeoJson = {
              ...raw,
              features: raw.features.filter((feature) => {
                  const iso =
                      feature?.properties?.ISO_A2_EH ??
                      feature?.properties?.ISO_A2 ??
                      feature?.properties?.iso_a2;
                  return iso !== "AQ";
              }),
          };
      }
      return cachedGeoJson;
  }

  /**
   * Adapter that turns a chart-lib widget class (`new Widget(node, opts).draw(data)`)
   * into the functional `(node, data, options)` shape the dispatcher
   * uses. Keeps the dispatch table flat.
   *
   * @param {{new (node: HTMLElement, options: object): {draw: (data: unknown) => unknown}}} Widget Chart-lib widget class.
   *
   * @returns {(node: HTMLElement, data: unknown, options: object) => unknown}
   */
  function fromChartLib(Widget) {
      return (node, data, options) => {
          const widget = new Widget(node, options);
          widget.draw(data);
          return widget;
      };
  }

  /**
   * Asynchronous world-map dispatcher. Fetches (and caches) the
   * geojson, then hands it to the chart-lib WorldMap widget alongside
   * a d3-geo Mercator projection. Same async return shape as the
   * other widgets even though they resolve synchronously, so callers
   * never have to special-case the map.
   *
   * @param {HTMLElement} node
   * @param {unknown}     data
   * @param {object}      options
   *
   * @returns {Promise<unknown>}
   */
  async function drawWorldMap(node, data, options) {
      const geojson = await loadWorldGeoJson();
      const widget = new WorldMap(node, {
          ...options,
          geojson,
          projection: geoMercator(),
      });
      widget.draw(data);
      return widget;
  }

  /**
   * Dispatch table mapping a `data-widget` attribute value to its
   * draw function. Every widget is a chart-lib widget; the world map
   * just needs a pre-fetch hop to load the GeoJSON the widget
   * consumes via its constructor.
   *
   * @type {Object<string, (node: HTMLElement, data: unknown, options: object) => unknown>}
   */
  const WIDGETS = {
      donut: fromChartLib(DonutChart),
      "world-map": drawWorldMap,
      "stream-graph": fromChartLib(StreamGraph),
      "sankey-flow": fromChartLib(SankeyFlow),
      "line-chart": fromChartLib(LineChart),
      "bar-chart": fromChartLib(BarChart),
      "stacked-bar": fromChartLib(StackedBar),
      "diverging-bar": fromChartLib(DivergingBar),
      "chord-diagram": fromChartLib(ChordDiagram),
      "name-bubbles": fromChartLib(NameBubbles),
      "month-radial": fromChartLib(MonthRadial),
      "gauge-arc": fromChartLib(GaugeArc),
      "mirror-histogram": fromChartLib(MirrorHistogram),
  };

  /**
   * Render every `[data-widget]` element inside `root` by dispatching
   * to the registered draw function. Each node carries its widget
   * type in `data-widget`, its serialised payload in `data-payload`,
   * and its renderer options in `data-options` (both JSON).
   *
   * Bootstrap popovers attached to chart-header info buttons are
   * initialised in the same pass so the consumer doesn't need a
   * second hook.
   *
   * @param {ParentNode} root Document fragment to scan.
   *
   * @returns {void}
   */
  function renderWidgets(root) {
      const nodes = root.querySelectorAll("[data-widget]");
      const bus = new DashboardBus();
      const widgets = [];

      nodes.forEach((node) => {
          const widget = WIDGETS[node.dataset.widget];

          if (widget === undefined) {
              return;
          }

          const data = parseJsonAttribute(node.dataset.payload, null);
          const options = parseJsonAttribute(node.dataset.options, {});

          // The chart partials emit the translated empty-state copy as
          // a `data-empty-message` attribute alongside the widget
          // marker. Hoist it into options so widgets pick up the
          // localised string instead of the built-in English fallback
          // ("No data available").
          if (
              typeof node.dataset.emptyMessage === "string" &&
              node.dataset.emptyMessage !== "" &&
              options.emptyMessage === undefined
          ) {
              options.emptyMessage = node.dataset.emptyMessage;
          }

          const instance = widget(node, data, options);

          // Async widgets (world-map) return a Promise instead of the
          // widget instance; connect the bus inside the .then so the
          // wiring happens once the widget has actually rendered.
          if (instance instanceof Promise) {
              instance.then((resolved) => connectToBus(resolved, bus, widgets));
          } else {
              connectToBus(instance, bus, widgets);
          }
      });

      initPopovers(root);
      initPlacesPanelTabs(root);
      return { bus, widgets };
  }

  /**
   * Wire up the Place-of-birth / Recorded-residences / Place-of-death
   * tab-switcher rendered by the PlacesPanel partial. The server
   * ships ALL three panels in the DOM with `.is-active` toggled on
   * the default; a click on a tab swaps that flag + the wrapper's
   * `data-view` attribute (which CSS reads to recolour the accent).
   * No widget re-instantiation — switching is purely a class toggle.
   *
   * @param {ParentNode} root Document fragment to scan.
   */
  function initPlacesPanelTabs(root) {
      root.querySelectorAll("[data-wt-stat-places]").forEach((wrap) => {
          const tabs = wrap.querySelectorAll(".wt-stat-places-tab");
          const panels = wrap.querySelectorAll(".wt-stat-places-panel");
          tabs.forEach((tab) => {
              tab.addEventListener("click", () => {
                  const targetView = tab.dataset.view;
                  if (typeof targetView !== "string" || targetView === "") {
                      return;
                  }
                  tabs.forEach((other) => {
                      const isActive = other === tab;
                      other.classList.toggle("is-active", isActive);
                      other.setAttribute("aria-selected", isActive ? "true" : "false");
                  });
                  panels.forEach((panel) => {
                      panel.classList.toggle("is-active", panel.dataset.view === targetView);
                  });
                  wrap.dataset.view = targetView;
              });
          });
      });
  }

  /**
   * Wire a single widget into the shared bus: emit clicks via
   * `bus.emit`, re-broadcast incoming selections via the widget's
   * `setSelection` hook. Widgets without a recognisable interface
   * (no `onSelectionChanged` / `setSelection`) are skipped silently
   * so the dispatcher stays additive — a future widget that opts in
   * to the bus only needs to expose the two hooks.
   *
   * The receiver ignores echoes of its own emission so a widget never
   * fights its own click via the round-trip.
   *
   * @param {object|null|undefined} instance
   * @param {DashboardBus}          bus
   * @param {Array<object>}         widgets   Mutated — every instance the bus accepted is pushed.
   * @returns {void}
   */
  function connectToBus(instance, bus, widgets) {
      if (instance === null || instance === undefined) {
          return;
      }
      if (typeof instance.onSelectionChanged !== "function") {
          return;
      }
      widgets.push(instance);
      const ownSource = typeof instance.options?.source === "string" ? instance.options.source : "";
      instance.onSelectionChanged((payload) => bus.emit(payload));
      bus.onSelectionChanged((payload) => {
          if (payload.source === ownSource && ownSource !== "") {
              return;
          }
          if (typeof instance.setSelection === "function") {
              instance.setSelection(payload.predicate);
          }
      });
  }

  /**
   * Parse a JSON-encoded dataset attribute, returning the fallback on
   * missing or unparsable input. Logs the parse error to the console
   * so a corrupt payload is debuggable but never breaks the render
   * loop for sibling widgets.
   *
   * @param {string|undefined} raw      The serialised JSON string.
   * @param {*}                fallback Value returned when parse fails / input is empty.
   *
   * @returns {*}
   */
  function parseJsonAttribute(raw, fallback) {
      if (raw === undefined || raw === "") {
          return fallback;
      }

      try {
          return JSON.parse(raw);
      } catch (error) {
          console.error("renderWidgets: unable to parse widget payload", error);
          return fallback;
      }
  }

  /**
   * Initialise Bootstrap popovers used by the "About this chart" info
   * buttons. Bootstrap ships with the webtrees vendor bundle and
   * exposes itself on `window.bootstrap`. getOrCreateInstance keeps
   * the call idempotent across re-renders.
   *
   * @param {ParentNode} root Document fragment to scan.
   */
  function initPopovers(root) {
      if (typeof window.bootstrap === "undefined" || !window.bootstrap.Popover) {
          return;
      }

      root.querySelectorAll('.wt-statistics-chart [data-bs-toggle="popover"]').forEach((element) => {
          window.bootstrap.Popover.getOrCreateInstance(element, { container: "body" });
      });
  }

  exports.renderWidgets = renderWidgets;

}));
