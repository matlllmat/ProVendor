

from flask import Flask, request, jsonify
import pandas as pd 
import pymysql
import logging
import math
import calendar as cal_lib
from prophet import Prophet
from prophet.utilities import regressor_coefficients
from scipy.stats import norm
from datetime import date as date_cls

DB = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'provendor',
    'cursorclass': pymysql.cursor.DictCursor
}

def get_db():
    return pymysql.connect(**DB)

@app.route('/forecast/category', medhods={'POST'})
def forecast_category():
    pass




if __name__ == "__main__":
    app.run(host='localhost', port=5000, debug=True)