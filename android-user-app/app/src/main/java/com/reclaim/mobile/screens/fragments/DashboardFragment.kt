package com.reclaim.mobile.screens.fragments

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.os.Bundle
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.screens.activities.MainActivity
import kotlinx.coroutines.launch

class DashboardFragment : Fragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_dashboard, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val repository = MobileRepository(requireContext())
        val welcome: TextView = view.findViewById(R.id.textDashboardWelcome)
        val reports: TextView = view.findViewById(R.id.textDashboardReports)
        val claims: TextView = view.findViewById(R.id.textDashboardClaims)
        val approved: TextView = view.findViewById(R.id.textDashboardApproved)
        val progress: ProgressBar = view.findViewById(R.id.progressDashboard)
        val empty: TextView = view.findViewById(R.id.textDashboardEmpty)
        val notificationsContainer: LinearLayout = view.findViewById(R.id.layoutDashboardNotifications)
        val reportButton: MaterialButton = view.findViewById(R.id.buttonDashboardReport)
        val myReportsButton: MaterialButton = view.findViewById(R.id.buttonDashboardMyReports)

        reportButton.setOnClickListener {
            (activity as? MainActivity)?.openReportItem()
        }

        myReportsButton.setOnClickListener {
            (activity as? MainActivity)?.openItemsScope("mine")
        }

        progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            repository.dashboard()
                .onSuccess { dashboard ->
                    welcome.text = "Welcome, ${dashboard.user.name}"
                    reports.text = "Reports: ${dashboard.stats.myReports}"
                    claims.text = "Claims: ${dashboard.stats.myClaims}"
                    approved.text = "Approved Claims: ${dashboard.stats.approvedClaims}"

                    notificationsContainer.removeAllViews()
                    if (dashboard.recentNotifications.isEmpty()) {
                        empty.visibility = View.VISIBLE
                    } else {
                        empty.visibility = View.GONE
                        dashboard.recentNotifications.forEach { notification ->
                            val textView = TextView(requireContext()).apply {
                                text = "${notification.title}\n${notification.timeAgo ?: notification.createdAt.orEmpty()}"
                                setPadding(0, 0, 0, 16)
                            }
                            notificationsContainer.addView(textView)
                        }
                    }
                }
                .onFailure {
                    empty.visibility = View.VISIBLE
                    empty.text = ApiErrorParser.message(it)
                    Toast.makeText(requireContext(), ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                }

            progress.visibility = View.GONE
        }
    }
}
