package com.reclaim.mobile.screens.activities

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.appbar.MaterialToolbar
import com.google.android.material.bottomnavigation.BottomNavigationView
import com.reclaim.mobile.R
import com.reclaim.mobile.screens.fragments.ClaimsFragment
import com.reclaim.mobile.screens.fragments.DashboardFragment
import com.reclaim.mobile.screens.fragments.ItemsFragment
import com.reclaim.mobile.screens.fragments.NotificationsFragment
import com.reclaim.mobile.screens.fragments.ProfileFragment

class MainActivity : AppCompatActivity() {

    private lateinit var bottomNavigationView: BottomNavigationView
    private lateinit var toolbar: MaterialToolbar
    private var currentItemsScope: String = "public"

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        toolbar = findViewById(R.id.toolbar)
        bottomNavigationView = findViewById(R.id.bottomNavigation)

        bottomNavigationView.setOnItemSelectedListener { item ->
            when (item.itemId) {
                R.id.nav_dashboard -> {
                    openDashboard()
                    true
                }
                R.id.nav_items -> {
                    openItemsScope(currentItemsScope)
                    true
                }
                R.id.nav_claims -> {
                    openClaims()
                    true
                }
                R.id.nav_notifications -> {
                    openNotifications()
                    true
                }
                R.id.nav_profile -> {
                    openProfile()
                    true
                }
                else -> false
            }
        }

        if (savedInstanceState == null) {
            bottomNavigationView.selectedItemId = R.id.nav_dashboard
        }
    }

    fun openItemsScope(scope: String) {
        currentItemsScope = scope
        if (bottomNavigationView.selectedItemId != R.id.nav_items) {
            bottomNavigationView.selectedItemId = R.id.nav_items
            return
        }
        toolbar.title = if (scope == "mine") "My Reports" else "Items"
        supportFragmentManager.beginTransaction()
            .replace(R.id.mainFragmentContainer, ItemsFragment.newInstance(scope))
            .commit()
    }

    fun openDashboard() {
        toolbar.title = getString(R.string.dashboard)
        supportFragmentManager.beginTransaction()
            .replace(R.id.mainFragmentContainer, DashboardFragment())
            .commit()
    }

    fun openClaims() {
        toolbar.title = getString(R.string.claims)
        supportFragmentManager.beginTransaction()
            .replace(R.id.mainFragmentContainer, ClaimsFragment())
            .commit()
    }

    fun openNotifications() {
        toolbar.title = getString(R.string.notifications)
        supportFragmentManager.beginTransaction()
            .replace(R.id.mainFragmentContainer, NotificationsFragment())
            .commit()
    }

    fun openProfile() {
        toolbar.title = getString(R.string.profile)
        supportFragmentManager.beginTransaction()
            .replace(R.id.mainFragmentContainer, ProfileFragment())
            .commit()
    }

    fun openReportItem() {
        startActivity(Intent(this, ReportItemActivity::class.java))
    }
}
